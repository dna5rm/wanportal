=head1 NAME

api_docs - public catalog of the api-docs markdown guides

=head1 SYNOPSIS

    # wired up by the cgi-bin/api dispatcher, OUTSIDE the JWT group
    use api_docs qw(register_api_docs);
    register_api_docs();

=head1 DESCRIPTION

GET /docs is the table of contents for the markdown guides rendered
by api-docs/index.php (Parsedown): every *.md in the docs directory,
as {name, title}. The title is the file's first "# " heading, with
the basename minus .md as fallback.

The directory is the one Parsedown reads, /srv/api-docs (docker-compose
bind-mounts this tree at /srv inside the container, so that is the
repo's api-docs/ directory). Like index.php, only .md basenames are
ever listed - Parsedown.php is not markdown and can never appear, and
no request input touches the path: the route takes no parameters, so
nothing supplied by a caller reaches the filesystem.

A missing or unreadable directory is simply an empty list, so a fresh
install answers 200 with no files instead of erroring.

=cut

package api_docs;
use strict;
use warnings;
use Exporter 'import';

our @EXPORT_OK = qw(register_api_docs list_api_docs);

# The directory Parsedown reads (api-docs/index.php requires
# /srv/api-docs/Parsedown.php), so /docs lists exactly what
# ?file=... can render.
my $docsdir = '/srv/api-docs';

# Title of a markdown doc: the first "# " (h1) heading, which every
# shipped guide opens with. At most the first 8KB are scanned so a
# large file cannot slow the listing down. Without a heading the
# basename minus .md stands in.
sub _doc_title {
    my ($path, $name) = @_;
    open my $fh, '<', $path or return _fallback_title($name);
    my $head = '';
    read $fh, $head, 8192;
    close $fh;
    for my $line (split /\n/, $head) {
        return $1 if $line =~ /\A\s*#\s+(.+?)\s*\z/;
    }
    return _fallback_title($name);
}

sub _fallback_title {
    my ($name) = @_;
    (my $title = $name) =~ s/\.md\z//;
    return $title;
}

# One {name, title} entry per *.md file in the docs directory, sorted
# by name. The extension filter alone keeps Parsedown.php out; hidden
# dotfiles and anything that is not a plain file are skipped too.
sub list_api_docs {
    my ($dir) = @_;
    $dir //= $docsdir;
    return [] unless defined $dir && -d $dir;

    opendir my $dh, $dir or return [];
    my @names = grep { /\.md\z/ && !/^\./ && -f "$dir/$_" } sort readdir $dh;
    closedir $dh;

    my @docs;
    for my $name (@names) {
        push @docs, {
            name  => $name,
            title => _doc_title("$dir/$name", $name),
        };
    }
    return \@docs;
}

sub register_api_docs {
    # @summary List API documentation files
    # @description Public endpoint listing the markdown guides under
    # /srv/api-docs, the same directory Parsedown renders as
    # /api-docs/index.php?file=... Only .md basenames are returned
    # (Parsedown.php is never listed), sorted by name, each with the
    # title from its first "# " heading. Read-only and parameter-free:
    # no request input ever reaches the filesystem.
    # @tags Public API
    main::get '/docs' => sub {
        my $c = shift;
        return $c->render(json => {
            status => 'success',
            files  => list_api_docs(),
        });
    };
}

1;