#!/usr/bin/env perl
# api_docs.t - the public /docs catalog stays parameter-free and tight.
#
# Pins, in two layers:
#
#   unit (temp directory, runs anywhere - api_docs.pm is core-only):
#     * list_api_docs() returns {name, title} for every plain *.md,
#       sorted by name
#     * the title is the file's first "# " heading, with the basename
#       minus .md as fallback
#     * Parsedown.php, non-.md files, dotfiles, and *.md directories
#       are skipped
#     * an empty or missing directory is an empty list (the route
#       answers 200 either way)
#
#   source (same extraction style as public_routes.t):
#     * the docs dir is the Parsedown path /srv/api-docs
#     * GET /docs is registered by api_docs.pm
#     * the dispatcher loads api_docs and calls register_api_docs()
#       in the PUBLIC section, before the JWT group
#
# Runs standalone (no Mojolicious needed; route registration is a
# runtime call, and the listing never touches main::get).

use strict;
use warnings;
use FindBin;
use lib "$FindBin::Bin/../../cgi-bin";
use Cwd qw(abs_path);
use File::Basename qw(dirname);
use File::Temp qw(tempdir);
use Test::More;

# api_docs.pm compiles main::get route registrations, so the parser
# must see main::get as a known sub before the module is required -
# the same predeclaration Mojolicious::Lite performs in the real
# dispatcher. It is never called in this test; register_api_docs()
# is the runtime path (that is why public .pm files are syntax-checked
# through cgi-bin/api, not on their own).
sub main::get;

use api_docs qw(list_api_docs);

my $root = abs_path(dirname(abs_path($0)) . '/../..');

sub write_file {
    my ($path, $content) = @_;
    open my $fh, '>', $path or die "open $path: $!";
    print $fh $content;
    close $fh;
}

# ---- unit: listing over a temp directory ------------------------------------
my $dir = tempdir(CLEANUP => 1);
write_file("$dir/b-second.md",  "intro prose\n\n# Second doc\n\nbody\n");
write_file("$dir/a-first.md",   "# First doc\n");
write_file("$dir/Parsedown.php", "<?php class Parsedown {}\n");
write_file("$dir/notes.txt",    "not markdown\n");
write_file("$dir/no-title.md",  "just prose, no heading\n");
write_file("$dir/.hidden.md",   "# hidden\n");
write_file("$dir/indented.md",  "text\n\n  # Indented heading\n");
mkdir "$dir/looks-like-dir.md";

my $docs = list_api_docs($dir);
is(ref $docs, 'ARRAY', 'list_api_docs returns an arrayref');

is_deeply(
    [map { $_->{name} } @$docs],
    [qw(a-first.md b-second.md indented.md no-title.md)],
    'only plain *.md basenames, sorted by name'
) or diag explain $docs;

is($docs->[0]{title}, 'First doc',        'title is the first "# " heading');
is($docs->[1]{title}, 'Second doc',       'title found after leading prose');
is($docs->[2]{title}, 'Indented heading', 'title found when indented');
is($docs->[3]{title}, 'no-title',         'missing heading falls back to basename minus .md');

for my $entry (@$docs) {
    is(ref $entry, 'HASH',         "entry for $entry->{name} is a hashref");
    ok(exists $entry->{name},      "entry for $entry->{name} has name");
    ok(exists $entry->{title},     "entry for $entry->{name} has title");
    ok(!exists $entry->{content},  "entry for $entry->{name} carries no content");
}

# ---- unit: empty and missing directories -------------------------------------
is_deeply(list_api_docs(tempdir(CLEANUP => 1)), [], 'empty directory -> empty list');
is_deeply(list_api_docs("$dir/does-not-exist"), [], 'missing directory -> empty list');

# ---- unit: the real docs directory (repo api-docs/, /srv/api-docs in-container)
{
    my $realdocs = list_api_docs("$root/api-docs");
    is(ref $realdocs, 'ARRAY', 'real api-docs dir listing returns an arrayref');

    opendir my $dh, "$root/api-docs" or die "opendir $root/api-docs: $!";
    my @disk_md = grep { /\.md\z/ && !/^\./ && -f "$root/api-docs/$_" } sort readdir $dh;
    closedir $dh;

    is_deeply(
        [map { $_->{name} } @$realdocs],
        \@disk_md,
        'real api-docs dir: every on-disk .md listed, sorted, nothing extra'
    );

    my %titles = map { $_->{name} => $_->{title} } @$realdocs;
    ok(!exists $titles{'Parsedown.php'}, 'Parsedown.php is never listed');
    for my $name (@disk_md) {
        like($titles{$name}, qr/\S/, "real doc $name has a non-empty title");
    }
}

# ---- source pins --------------------------------------------------------------
{
    my $mod;
    open my $fh, '<', "$root/cgi-bin/api_docs.pm" or plan skip_all => "cannot open api_docs.pm";
    $mod = do { local $/; <$fh> };
    close $fh;

    like($mod, qr{my \$docsdir = '/srv/api-docs';},
        'docs dir is the path Parsedown uses: /srv/api-docs');
    like($mod, qr/main::get\s+'\/docs'\s*=>/,
        'api_docs.pm registers GET /docs');
    unlike($mod, qr/main::(post|put|del|delete|patch)\b/,
        'api_docs.pm only registers a GET route');
}

{
    my $api;
    open my $fh, '<', "$root/cgi-bin/api" or plan skip_all => 'cannot open cgi-bin/api';
    $api = do { local $/; <$fh> };
    close $fh;

    like($api, qr/use\s+api_docs\s+qw\(register_api_docs\)\s*;/,
        'dispatcher loads api_docs');

    # Everything before the JWT group { ... } block is the public section.
    my ($public) = $api =~ /\A(.*?)^\s*group\s*\{/ms;
    ok(defined $public && length $public, 'dispatcher has a JWT group to compare against');
    like($public, qr/register_api_docs\s*\(\s*\)\s*;/,
        'register_api_docs() is called in the public section (before the JWT group)');
}

done_testing();