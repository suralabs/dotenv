<?php
/**
 * This file is part of PHP DotEnv
 * @author Vitor Reis <vitor@d5w.com.br>
 */

namespace DotEnv\Common;

use DotEnv\Exception\Loader;
use DotEnv\Exception\Runtime;
use DotEnv\Exception\Syntax;

/**
 * Trait Parser
 * @package DotEnv\Common
 */
trait Parser
{
    /**
     * Method for load .env files
     * @param  string          ...$paths .env paths
     * @return string[]|false            If success return "array", else "false"
     * @throws Loader|Runtime|Syntax
     */
    public function load(...$paths)
    {
        if (!$paths) {
            # Load .env in current file directory
            $paths = [ './.env' ];
        }

        $count = 0;
        $result = [];
        do {
            $path = array_shift($paths);

            if (!is_file($path)) {
                if (static::isDebug()) {
                    throw new Loader("File \"$path\" not found");
                } else {
                    continue;
                }
            }

            if (!is_null($parse = $this->parse(file_get_contents($path, FILE_TEXT), realpath($path)))) {
                $result = array_merge($result, $parse);
                $count++;
            }
        } while ($paths);

        if ($count) {
            return $result;
        } elseif (static::isDebug()) {
            throw new Loader("None env variable loaded");
        } else {
            return false;
        }
    }

    /**
     * Method for parse .env content
     * @param string         $content .env Content
     * @param string|null    $path    .env Path
     * @return string[]|null          If success "string[]", else "null"
     * @throws Loader|Runtime|Syntax
     */
    public function parse($content, $path = null)
    {
        # EMPTY CONTENT
        if (!$content) {
            return [];
        }

        # FIX BREAK LINE
        $content = str_replace([ '\r', "\r", '\n' ], [ "", "", "\n" ], $content);

        # GET LINES
        $lines = explode("\n", $content);

        $lineno = 0;
        $result = [];
        do {
            $lineno++;
            $line = ltrim(array_shift($lines));

            # EMPTY LINE OR COMMENT
            if (empty($line) || $line[0] === '#') {
                continue;
            }

            # INVALIDATE EMPTY DELIMITER
            if (strpos($line, '=') === false) {
                if (static::isDebug()) {
                    throw new Syntax('Value not defined', $path, $lineno);
                } else {
                    continue;
                }
            }

            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            # INVALIDATE EMPTY KEY
            if (empty($key)) {
                if (static::isDebug()) {
                    throw new Syntax('Key not defined', $path, $lineno);
                } else {
                    continue;
                }
            }

            # INVALIDATE KEY NOT STARTED WITH [a-zA-Z_]
            if (!preg_match("/[a-zA-Z_]/", $key[0])) {
                throw new Syntax('Key not defined', $path, $lineno);
            }

            # INVALIDATE KEY CHARACTERS NOT [a-zA-Z0-9_]
            if (preg_match("/\W/", $key)) {
                if (static::isDebug()) {
                    throw new Syntax('Key invalid character', $path, $lineno);
                } else {
                    continue;
                }
            }

            $value = ltrim($value);
            if (empty($value)) {
                # EMPTY VALUE
                $value = '';
            } else {
                switch ($value[0]) {
                    # QUOTED VALUE
                    case "'":
                    case '"':
                        $lineno_start = $lineno;
                        $append = false;

                        do {
                            $value .= $append !== false ? "\n$append" : '';

                            if (preg_match(
                                "/($value[0])((?:\\\\$value[0]|[^$value[0]])+)(\\1|$)/",
                                $value,
                                $matches
                            )) {
                                if ($matches[1] === $value[0] && $matches[3] === $value[0]) {
                                    break;
                                }
                            }

                            $matches = null;
                            $append = array_shift($lines);
                            $lineno++;
                        } while (!is_null($append));

                        if ($matches) {
                            $value = $matches[2];
                        } elseif (static::isDebug()) {
                            throw new Syntax('Quote not closed', $path, $lineno_start);
                        } else {
                            continue 2;
                        }
                        break;

                    # SIMPLE VALUE
                    default:
                        # INVALIDATE SPACE IN VALUE NOT QUOTED
                        if (stripos($value, " ") !== false) {
                            if (static::isDebug()) {
                                throw new Syntax('Value invalid character', $path, $lineno);
                            } else {
                                continue 2;
                            }
                        }
                        break;
                }
            }

            # FILTRATE VALUE
            $this->converter($key, $value);

            # INVALIDATE VALUE
            if ($rule = $this->invalidate($key, $value)) {
                throw new Loader("Value \"$key\" does not meet rule \"$rule\"!");
            }

            # SAVE VALUE
            $result[$key] = $value;
            $this->put($key, $value);
        } while ($lines);

        # CHECK REQUIRE LIST
        if ($requiredKey = $this->invalidateRequiredList()) {
            throw new Loader("Env key \"$requiredKey\" required, is not defined!");
        }

        return $result;
    }
}