/*
 * Single-quote a value so it survives a POSIX shell byte for byte.
 * The api hands the installer commands, and agent passwords are
 * randomly generated — they can carry shell metacharacters ($,
 * backticks, backslashes, !, parens). Inside double quotes the shell
 * expands $ and friends before the command ever runs, so a pasted
 * install line hands the agent a silently different secret (a real
 * install answered 401 because of exactly this). Single quotes keep
 * every byte literal; the one character they cannot carry is the
 * single quote itself, which the standard escape carries by closing
 * the quote, inserting an escaped quote, and reopening: ' -> '\''.
 * The *** placeholder the page shows non-admins quotes the same way
 * ('***') — harmless, and just as safe.
 */
export function shellSingleQuote(s) {
    return "'" + String(s).replace(/'/g, "'\\''") + "'"
}