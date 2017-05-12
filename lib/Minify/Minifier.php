<?php

/*!
 * CssMin
 * Author: Tubal Martin - http://tubalmartin.me/
 * Repo: https://github.com/tubalmartin/YUI-CSS-compressor-PHP-port
 *
 * This is a PHP port of the CSS minification tool distributed with YUICompressor,
 * itself a port of the cssmin utility by Isaac Schlueter - http://foohack.com/
 * Permission is hereby granted to use the PHP version under the same
 * conditions as the YUICompressor.
 */

/*!
 * YUI Compressor
 * http://developer.yahoo.com/yui/compressor/
 * Author: Julien Lecomte - http://www.julienlecomte.net/
 * Copyright (c) 2013 Yahoo! Inc. All rights reserved.
 * The copyrights embodied in the content of this file are licensed
 * by Yahoo! Inc. under the BSD (revised) open source license.
 */

class Minify0_Minifier
{
    const NL = '___YUICSSMIN_NL___';
    const CLASSCOLON = '___YUICSSMIN_PSEUDOCLASSCOLON___';
    const QUERY_FRACTION = '___YUICSSMIN_QUERY_FRACTION___';
    const TOKEN = '___YUICSSMIN_PRESERVED_TOKEN_';
    const COMMENT = '___YUICSSMIN_PRESERVE_CANDIDATE_COMMENT_';
    const AT_RULE_BLOCK = '___YUICSSMIN_PRESERVE_AT_RULE_BLOCK_';

    private $comments;
    private $atRuleBlocks;
    private $preservedTokens;
    private $chunkLength = 5000;
    private $minChunkLength = 100;
    private $memoryLimit;
    private $maxExecutionTime = 60; // 1 min
    private $pcreBacktrackLimit;
    private $pcreRecursionLimit;
    private $raisePhpLimits;
    private $hexToNamedColorsMap;
    private $namedToHexColorsMap;
    private $namedToHexColorsRegex;
    private $numRegex;
    private $shortenOneZeroesRegex;
    private $shortenTwoZeroesRegex;
    private $shortenThreeZeroesRegex;
    private $shortenFourZeroesRegex;
    private $unitsGroupRegex = '(?:ch|cm|em|ex|gd|in|mm|px|pt|pc|q|rem|vh|vmax|vmin|vw|%)';

    /**
     * @param bool|int $raisePhpLimits If true, PHP settings will be raised if needed
     */
    public function __construct($raisePhpLimits = true)
    {
        $this->raisePhpLimits = (bool) $raisePhpLimits;
        $this->memoryLimit = 128 * 1048576; // 128MB in bytes
        $this->pcreBacktrackLimit = 1000 * 1000;
        $this->pcreRecursionLimit = 500 * 1000;
        $this->hexToNamedColorsMap = Minify0_Colors::getHexToNamedMap();
        $this->namedToHexColorsMap = Minify0_Colors::getNamedToHexMap();
        $this->namedToHexColorsRegex = sprintf(
            '/(?<!\\\\)(:|,|\(| )(%s)(;|\}|,|\)| )/Si',
            implode('|', array_keys($this->namedToHexColorsMap))
        );
        $this->numRegex = sprintf('(?:\+|-)?\d*\.?\d+%s?', $this->unitsGroupRegex);
        $this->setShortenZeroValuesRegexes();
    }

    /**
     * Parses & minifies the given input CSS string
     * @param string $css
     * @param int|bool $linebreakPos
     * @return string
     */
    public function run($css = '', $linebreakPos = 0)
    {
        if (empty($css)) {
            return '';
        }

        $linebreakPos = (int) $linebreakPos;

        if ($this->raisePhpLimits) {
            $this->doRaisePhpLimits();
        }

        $this->comments = array();
        $this->atRuleBlocks = array();
        $this->preservedTokens = array();

        // Process data urls
        $css = $this->processDataUrls($css);

        // Process comments
        $css = preg_replace_callback(
            '/(?<!\\\\)\/\*(.*?)\*(?<!\\\\)\//Ss',
            array($this, 'processCommentsCallback'),
            $css
        );

        // IE7: Process Microsoft matrix filters (whitespaces between Matrix parameters). Can contain strings inside.
        $css = preg_replace_callback(
            '/filter:\s*progid:DXImageTransform\.Microsoft\.Matrix\(([^)]+)\)/Ss',
            array($this, 'processOldIeSpecificMatrixDefinitionCallback'),
            $css
        );

        // Process quoted unquotable attribute selectors to unquote them. Covers most common cases.
        // Likelyhood of a quoted attribute selector being a substring in a string: Very very low.
        $css = preg_replace(
            '/\[\s*([a-z][a-z-]+)\s*([\*\|\^\$~]?=)\s*[\'"](-?[a-z_][a-z0-9-_]+)[\'"]\s*\]/Ssi',
            '[$1$2$3]',
            $css
        );

        // Process strings so their content doesn't get accidentally minified
        $css = preg_replace_callback(
            '/(?:"(?:[^\\\\"]|\\\\.|\\\\)*")|'."(?:'(?:[^\\\\']|\\\\.|\\\\)*')/S",
            array($this, 'processStringsCallback'),
            $css
        );

        // Strings are safe, now wrestle the comments
        $css = $this->processComments($css);

        // Safe chunking: process at-rule blocks so after chunking nothing gets stripped out
        $css = preg_replace_callback(
            '/@(?:document|(?:-(?:atsc|khtml|moz|ms|o|wap|webkit)-)?keyframes|media|supports).+?\}\s*\}/Ssi',
            array($this, 'processAtRuleBlocksCallback'),
            $css
        );

        // Let's divide css code in chunks of {$this->chunkLength} chars aprox.
        // Reason: PHP's PCRE functions like preg_replace have a "backtrack limit"
        // of 100.000 chars by default (php < 5.3.7) so if we're dealing with really
        // long strings and a (sub)pattern matches a number of chars greater than
        // the backtrack limit number (i.e. /(.*)/s) PCRE functions may fail silently
        // returning NULL and $css would be empty.
        $charset = '';
        $charsetRegexp = '/(@charset)( [^;]+;)/Si';
        $cssChunks = array();
        $l = strlen($css);

        // Do not chunk when the number of characters is <= {$this->chunkLength}
        if ($l <= $this->chunkLength) {
            $cssChunks[] = $css;
        } else {
            $startIndex = 0;
            $offset = $this->chunkLength;

            // Chunk css code in a safe way
            while (preg_match('/(?<!\\\\)\}/S', $css, $matches, PREG_OFFSET_CAPTURE, $offset)) {
                $matchIndex = $matches[0][1];
                $nextStartIndex = $matchIndex + 1;
                $cssChunks[] = substr($css, $startIndex, $nextStartIndex - $startIndex);
                $startIndex = $nextStartIndex;
                $offset = $matchIndex + $this->chunkLength;
                if ($offset > $l) {
                    break;
                }
            }

            // Final chunk
            $cssChunks[] = substr($css, $startIndex);
        }

        // Minify each chunk
        foreach ($cssChunks as &$cssChunk) {
            $cssChunk = $this->minify($cssChunk);

            // Keep the first @charset at-rule found
            if (empty($charset) && preg_match($charsetRegexp, $cssChunk, $matches)) {
                $charset = strtolower($matches[1]) . $matches[2];
            }

            // Delete all @charset at-rules
            $cssChunk = preg_replace($charsetRegexp, '', $cssChunk);
        }

        // Update the first chunk and push the charset to the top of the file.
        $cssChunks[0] = $charset . $cssChunks[0];

        $css = trim(implode('', $cssChunks));

        // Restore preserved comments and strings
        foreach ($this->preservedTokens as $tokenId => $token) {
            $css = preg_replace('/'. $tokenId .'/', Minify0_Utils::escapeReplacementString($token), $css, 1);
        }

        // Some source control tools don't like it when files containing lines longer
        // than, say 8000 characters, are checked in. The linebreak option is used in
        // that case to split long lines after a specific column.
        if ($linebreakPos > 0) {
            $offset = $linebreakPos;
            while (preg_match('/(?<!\\\\)\}(?!\n)/S', $css, $matches, PREG_OFFSET_CAPTURE, $offset)) {
                $matchIndex = $matches[0][1];
                $css = substr_replace($css, "\n", $matchIndex + 1, 0);
                $offset = $matchIndex + 2 + $linebreakPos;
                if ($offset > $l) {
                    break;
                }
            }
        }

        return $css;
    }

    /**
     * Sets the approximate number of characters to use when splitting a string in chunks.
     * @param int $length
     */
    public function setChunkLength($length)
    {
        $length = (int) $length;
        $this->chunkLength = $length < $this->minChunkLength ? $this->minChunkLength : $length;
    }

    /**
     * Sets the memory limit for this script
     * @param int|string $limit
     */
    public function setMemoryLimit($limit)
    {
        $this->memoryLimit = Minify0_Utils::normalizeInt($limit);
    }

    /**
     * Sets the maximum execution time for this script
     * @param int|string $seconds
     */
    public function setMaxExecutionTime($seconds)
    {
        $this->maxExecutionTime = (int) $seconds;
    }

    /**
     * Sets the PCRE backtrack limit for this script
     * @param int $limit
     */
    public function setPcreBacktrackLimit($limit)
    {
        $this->pcreBacktrackLimit = (int) $limit;
    }

    /**
     * Sets the PCRE recursion limit for this script
     * @param int $limit
     */
    public function setPcreRecursionLimit($limit)
    {
        $this->pcreRecursionLimit = (int) $limit;
    }

    /**
     * Tries to configure PHP to use at least the suggested minimum settings
     * @return void
     */
    private function doRaisePhpLimits()
    {
        $phpLimits = array(
            'memory_limit' => $this->memoryLimit,
            'max_execution_time' => $this->maxExecutionTime,
            'pcre.backtrack_limit' => $this->pcreBacktrackLimit,
            'pcre.recursion_limit' =>  $this->pcreRecursionLimit
        );

        // If current settings are higher respect them.
        foreach ($phpLimits as $name => $suggested) {
            $current = Minify0_Utils::normalizeInt(ini_get($name));

            if ($current > $suggested) {
                continue;
            }

            // memoryLimit exception: allow -1 for "no memory limit".
            if ($name === 'memory_limit' && $current === -1) {
                continue;
            }

            // maxExecutionTime exception: allow 0 for "no memory limit".
            if ($name === 'max_execution_time' && $current === 0) {
                continue;
            }

            ini_set($name, $suggested);
        }
    }

    /**
     * Builds regular expressions needed for shortening zero values
     */
    private function setShortenZeroValuesRegexes()
    {
        $zeroRegex = '0'. $this->unitsGroupRegex;
        $numOrPosRegex = '('. $this->numRegex .'|top|left|bottom|right|center) ';
        $oneZeroSafeProperties = array(
            '(?:line-)?height',
            '(?:(?:min|max)-)?width',
            'top',
            'left',
            'background-position',
            'bottom',
            'right',
            'border(?:-(?:top|left|bottom|right))?(?:-width)?',
            'border-(?:(?:top|bottom)-(?:left|right)-)?radius',
            'column-(?:gap|width)',
            'margin(?:-(?:top|left|bottom|right))?',
            'outline-width',
            'padding(?:-(?:top|left|bottom|right))?'
        );

        // First zero regex
        $regex = '/(;|\{)('. implode('|', $oneZeroSafeProperties) .'):%s/Si';
        $this->shortenOneZeroesRegex = sprintf($regex, $zeroRegex);

        // Multiple zeroes regexes
        $regex = '/(;|\{)(margin|padding|background-position):%s/Si';
        $this->shortenTwoZeroesRegex = sprintf($regex, $numOrPosRegex . $zeroRegex);
        $this->shortenThreeZeroesRegex = sprintf($regex, $numOrPosRegex . $numOrPosRegex . $zeroRegex);
        $this->shortenFourZeroesRegex = sprintf($regex, $numOrPosRegex . $numOrPosRegex . $numOrPosRegex . $zeroRegex);
    }

    /**
     * Registers a token of a type
     * @param $token
     * @param $tokenType
     * @param $tokenList
     * @return string
     */
    private function registerToken($token, $tokenType, &$tokenList)
    {
        $tokenId = $tokenType . count($tokenList) .'___';
        $tokenList[$tokenId] = $token;
        return $tokenId;
    }

    /**
     * Registers a preserved token
     * @param $token
     * @return string The token ID string
     */
    private function registerPreservedToken($token)
    {
        return $this->registerToken($token, self::TOKEN, $this->preservedTokens);
    }

    /**
     * Registers a candidate comment token
     * @param $comment
     * @return string The comment token ID string
     */
    private function registerComment($comment)
    {
        return $this->registerToken($comment, self::COMMENT, $this->comments);
    }

    /**
     * Registers an at rule block token
     * @param $block
     * @return string The comment token ID string
     */
    private function registerAtRuleBlock($block)
    {
        return $this->registerToken($block, self::AT_RULE_BLOCK, $this->atRuleBlocks);
    }

    /**
     * Minifies the given input CSS string
     * @param string $css
     * @return string
     */
    private function minify($css)
    {
        // Restore preserved at rule blocks
        foreach ($this->atRuleBlocks as $atRuleBlockId => $atRuleBlock) {
            $atRuleBlockIdRegex = '/'. $atRuleBlockId .'/';
            if (preg_match($atRuleBlockIdRegex, $css)) {
                $css = preg_replace($atRuleBlockIdRegex, Minify0_Utils::escapeReplacementString($atRuleBlock), $css, 1);
                unset($this->atRuleBlocks[$atRuleBlockId]);
            }
        }

        // Normalize all whitespace strings to single spaces. Easier to work with that way.
        $css = preg_replace('/\s+/', ' ', $css);

        // Remove spaces before & after newlines
        $css = preg_replace('/ ?'. self::NL .' ?/S', self::NL, $css);

        // Shorten & preserve calculations calc(...) since spaces are important
        $css = preg_replace_callback('/calc(\(((?:[^()]+|(?1))*)\))/Si', array($this, 'processCalcCallback'), $css);

        // Replace positive sign from numbers preceded by : or a white-space before the leading space is removed
        // +1.2em to 1.2em, +.8px to .8px, +2% to 2%
        $css = preg_replace('/((?<!\\\\):| )\+(\.?\d+)/S', '$1$2', $css);

        // Remove leading zeros from integer and float numbers preceded by : or a white-space
        // 000.6 to .6, -0.8 to -.8, 0050 to 50, -01.05 to -1.05
        $css = preg_replace('/((?<!\\\\):| )(-?)0+(\.?\d+)/S', '$1$2$3', $css);

        // Remove trailing zeros from float numbers preceded by : or a white-space
        // -6.0100em to -6.01em, .0100 to .01, 1.200px to 1.2px
        $css = preg_replace('/((?<!\\\\):| )(-?)(\d?\.\d+?)0+([^\d])/S', '$1$2$3$4', $css);

        // Remove trailing .0 -> -9.0 to -9
        $css = preg_replace('/((?<!\\\\):| )(-?\d+)\.0([^\d])/S', '$1$2$3', $css);

        // Replace 0 length numbers with 0
        $css = preg_replace('/((?<!\\\\):| )-?\.?0+([^\d])/S', '${1}0$2', $css);

        // Preserve pseudo-class colons in selectors before removing spaces before
        // the things that should not have spaces before them.
        // We must avoid turning "p :link {...}" into "p:link{...}".
        $css = preg_replace('/((?:^|\})[^{]* ):/S', '$1'.self::CLASSCOLON, $css);

        // Remove spaces before the things that should not have spaces before them.
        $css = preg_replace('/ ([!{};:>+()\]~=,])/S', '$1', $css);

        // Bring the pseudo-class colon back
        $css = preg_replace('/'. self::CLASSCOLON .'/', ':', $css);

        // Restore spaces for !important
        $css = preg_replace('/!important/i', ' !important', $css);

        // retain space for special IE6 cases
        $css = preg_replace_callback('/:first-(line|letter)(\{|,)/Si', function ($matches) {
            return ':first-'. strtolower($matches[1]) .' '. $matches[2];
        }, $css);

        // no space after the end of a preserved comment
        $css = preg_replace('/\*\/ /', '*/', $css);

        // lowercase some popular @directives
        $css = preg_replace_callback(
            '/@(document|font-face|import|(?:-(?:atsc|khtml|moz|ms|o|wap|webkit)-)?keyframes|media|namespace|page|' .
            'supports|viewport)/Si',
            function ($matches) {
                return '@'. strtolower($matches[1]);
            },
            $css
        );

        // lowercase some more common pseudo-elements
        $css = preg_replace_callback(
            '/:(active|after|before|checked|disabled|empty|enabled|first-(?:child|of-type)|focus|hover|' .
            'last-(?:child|of-type)|link|only-(?:child|of-type)|root|:selection|target|visited)/Si',
            function ($matches) {
                return ':'. strtolower($matches[1]);
            },
            $css
        );

        // lowercase some more common functions
        $css = preg_replace_callback(
            '/:(lang|not|nth-child|nth-last-child|nth-last-of-type|nth-of-type|(?:-(?:moz|webkit)-)?any)\(/Si',
            function ($matches) {
                return ':'. strtolower($matches[1]) .'(';
            },
            $css
        );

        // lower case some common function that can be values
        // NOTE: rgb() isn't useful as we replace with #hex later, as well as and() is already done for us
        $css = preg_replace_callback(
            '/([:,( ] ?)(attr|color-stop|from|rgba|to|url|-webkit-gradient|' .
            '(?:-(?:atsc|khtml|moz|ms|o|wap|webkit)-)?(?:calc|max|min|(?:repeating-)?(?:linear|radial)-gradient))/Si',
            function ($matches) {
                return $matches[1] . strtolower($matches[2]);
            },
            $css
        );

        // Put the space back in some cases, to support stuff like
        // @media screen and (-webkit-min-device-pixel-ratio:0){
        $css = preg_replace_callback('/( |\) )(and|not|or)\(/Si', function ($matches) {
            return $matches[1] . strtolower($matches[2]) .' (';
        }, $css);

        // Remove the spaces after the things that should not have spaces after them.
        $css = preg_replace('/([!{}:;>+(\[~=,]) /S', '$1', $css);

        // remove unnecessary semicolons
        $css = preg_replace('/;+\}/S', '}', $css);

        // Fix for issue: #2528146
        // Restore semicolon if the last property is prefixed with a `*` (lte IE7 hack)
        // to avoid issues on Symbian S60 3.x browsers.
        $css = preg_replace('/(\*[a-z0-9-]+:[^;}]+)(\})/S', '$1;$2', $css);

        // Shorten zero values for safe properties only
        $css = $this->shortenZeroValues($css);

        // Shorten font-weight values
        $css = preg_replace('/(font-weight:)bold\b/Si', '${1}700', $css);
        $css = preg_replace('/(font-weight:)normal\b/Si', '${1}400', $css);

        // Shorten suitable shorthand properties with repeated non-zero values
        $css = preg_replace(
            '/(margin|padding):('.$this->numRegex.') ('.$this->numRegex.') (?:\2) (?:\3)(;|\}| !)/Si',
            '$1:$2 $3$4',
            $css
        );
        $css = preg_replace(
            '/(margin|padding):('.$this->numRegex.') ('.$this->numRegex.') ('.$this->numRegex.') (?:\3)(;|\}| !)/Si',
            '$1:$2 $3 $4$5',
            $css
        );

        // Shorten colors from rgb(51,102,153) to #336699, rgb(100%,0%,0%) to #ff0000 (sRGB color space)
        // Shorten colors from hsl(0, 100%, 50%) to #ff0000 (sRGB color space)
        // This makes it more likely that it'll get further compressed in the next step.
        $css = preg_replace_callback('/rgb\(([0-9,.% -]+)\)(.{1})/Si', array($this, 'rgbToHexCallback'), $css);
        $css = preg_replace_callback('/hsl\(([0-9,.% -]+)\)(.{1})/Si', array($this, 'hslToHexCallback'), $css);

        // Shorten colors from #AABBCC to #ABC or shorter color name.
        // Look for hex colors which don't have a =, or a " in front of them (to avoid filters)
        $css = preg_replace_callback(
            '/(= ?["\']?)?#([0-9a-f])([0-9a-f])([0-9a-f])([0-9a-f])([0-9a-f])([0-9a-f])(;|,|\}|\)|"|\'| )/Si',
            array($this, 'shortenHexColorsCallback'),
            $css
        );

        // Shorten long named colors with a shorter HEX counterpart: white -> #fff.
        // Run at least 2 times to cover most cases
        $css = preg_replace_callback($this->namedToHexColorsRegex, array($this, 'shortenNamedColorsCallback'), $css);
        $css = preg_replace_callback($this->namedToHexColorsRegex, array($this, 'shortenNamedColorsCallback'), $css);

        // shorter opacity IE filter
        $css = preg_replace('/progid:DXImageTransform\.Microsoft\.Alpha\(Opacity=/Si', 'alpha(opacity=', $css);

        // Find a fraction that is used for Opera's -o-device-pixel-ratio query
        // Add token to add the "/" back in later
        $css = preg_replace('/\(([a-z-]+):([0-9]+)\/([0-9]+)\)/Si', '($1:$2'. self::QUERY_FRACTION .'$3)', $css);

        // Patch new lines to avoid being removed when followed by empty rules cases
        $css = preg_replace('/'. self::NL .'/', self::NL .'}', $css);

        // Remove empty rules: First pass.
        $css = preg_replace('/[^{};\/]+\{\}/S', '', $css);

        // Remove empty rules: Second pass to remove blocks whose only content is an empty rule removed in first pass.
        $css = preg_replace('/[^{};\/]+\{\}/S', '', $css);

        // Restore new lines for /*! important comments
        $css = preg_replace('/'. self::NL .'\}/', "\n", $css);

        // Add "/" back to fix Opera -o-device-pixel-ratio query
        $css = preg_replace('/'. self::QUERY_FRACTION .'/', '/', $css);

        // Replace multiple semi-colons in a row by a single one
        // See SF bug #1980989
        $css = preg_replace('/;;+/S', ';', $css);

        // Lowercase all uppercase properties
        $css = preg_replace_callback('/(\{|;)([A-Z-]+)(:)/S', function ($matches) {
            return $matches[1] . strtolower($matches[2]) . $matches[3];
        }, $css);

        // Trim the final string for any leading or trailing white space but respect newlines!
        return trim($css, ' ');
    }

    /**
     * Searches & replaces all data urls with tokens before we start compressing,
     * to avoid performance issues running some of the subsequent regexes against large string chunks.
     * @param string $css
     * @return string
     */
    private function processDataUrls($css)
    {
        $sb = array();
        $searchOffset = $substrOffset = 0;
        $pattern = '/url\(\s*(["\']?)data:/i';

        // Since we need to account for non-base64 data urls, we need to handle
        // ' and ) being part of the data string.
        while (preg_match($pattern, $css, $m, PREG_OFFSET_CAPTURE, $searchOffset)) {
            $matchStartIndex = $m[0][1];
            $dataStartIndex = $matchStartIndex + 4; // url( length
            $searchOffset = $matchStartIndex + strlen($m[0][0]);
            $terminator = $m[1][0]; // ', " or empty (not quoted)
            $terminatorRegex = strlen($terminator) === 0 ? '/(?<!\\\\)(\))/' : '/(?<!\\\\)'.$terminator.'\s*(\))/';

            // Start moving stuff over to the buffer
            $sb[] = substr($css, $substrOffset, $matchStartIndex - $substrOffset);

            // Terminator found
            if (preg_match($terminatorRegex, $css, $matches, PREG_OFFSET_CAPTURE, $searchOffset)) {
                $matchEndIndex = $matches[1][1];
                $searchOffset = $matchEndIndex + 1;
                $token = substr($css, $dataStartIndex, $matchEndIndex - $dataStartIndex);

                // Remove all spaces only for base64 encoded URLs.
                if (preg_match('/base64,/si', $token)) {
                    $token = preg_replace('/\s+/', '', $token);
                }

                $sb[] = 'url('. $this->registerPreservedToken(trim($token)) .')';
            // No end terminator found, re-add the whole match. Should we throw/warn here?
            } else {
                $sb[] = substr($css, $matchStartIndex, $searchOffset - $matchStartIndex);
            }

            $substrOffset = $searchOffset;
        }

        $sb[] = substr($css, $substrOffset);

        return implode('', $sb);
    }

    /**
     * Registers all comments found as candidates to be preserved.
     * @param $matches
     * @return string
     */
    private function processCommentsCallback($matches)
    {
        $match = !empty($matches[1]) ? $matches[1] : '';
        return '/*'. $this->registerComment($match) .'*/';
    }

    /**
     * Preserves or removes comments found.
     * @param $css
     * @return string
     */
    private function processComments($css)
    {
        foreach ($this->comments as $commentId => $comment) {
            $commentIdRegex = '/'. $commentId .'/';

            // ! in the first position of the comment means preserve
            // so push to the preserved tokens keeping the !
            if (preg_match('/^!/', $comment)) {
                $preservedTokenId = $this->registerPreservedToken($comment);
                $css = preg_replace($commentIdRegex, $preservedTokenId, $css, 1);
                // Preserve new lines for /*! important comments
                $css = preg_replace('/\R+\s*(\/\*'. $preservedTokenId .')/', self::NL.'$1', $css);
                $css = preg_replace('/('. $preservedTokenId .'\*\/)\s*\R+/', '$1'.self::NL, $css);
                continue;
            }

            // keep empty comments after child selectors (IE7 hack)
            // e.g. html >/**/ body
            if (strlen($comment) === 0 && preg_match('/>\/\*'.$commentId.'/', $css)) {
                $css = preg_replace($commentIdRegex, $this->registerPreservedToken(''), $css, 1);
                continue;
            }

            // in all other cases kill the comment
            $css = preg_replace('/\/\*' . $commentId . '\*\//', '', $css, 1);
        }

        return $css;
    }

    /**
     * Preserves old IE Matrix string definition
     * @param $matches
     * @return string
     */
    private function processOldIeSpecificMatrixDefinitionCallback($matches)
    {
        return 'filter:progid:DXImageTransform.Microsoft.Matrix('. $this->registerPreservedToken($matches[1]) .')';
    }

    /**
     * Preserves strings found
     * @param $matches
     * @return string
     */
    private function processStringsCallback($matches)
    {
        $match = $matches[0];
        $quote = substr($match, 0, 1);
        $match = substr($match, 1, -1);

        // maybe the string contains a comment-like substring?
        // one, maybe more? put'em back then
        if (($pos = strpos($match, self::COMMENT)) !== false) {
            foreach ($this->comments as $commentId => $comment) {
                $match = preg_replace('/'. $commentId .'/', Minify0_Utils::escapeReplacementString($comment), $match, 1);
            }
        }

        // minify alpha opacity in filter strings
        $match = preg_replace('/progid:DXImageTransform\.Microsoft\.Alpha\(Opacity=/i', 'alpha(opacity=', $match);

        return $quote . $this->registerPreservedToken($match) . $quote;
    }

    /**
     * Preserves At-rule blocks found temporarily for safe chunking.
     * @param $matches
     * @return string
     */
    private function processAtRuleBlocksCallback($matches)
    {
        return $this->registerAtRuleBlock($matches[0]);
    }

    /**
     * Preserves and shortens calculations since spaces inside them are very important.
     * @param $matches
     * @return string
     */
    private function processCalcCallback($matches)
    {
        $token = preg_replace('/ (\+|-) /', '_$1_', trim($matches[2]));
        $token = preg_replace('/ ?(\*|\/|\(|\)|,) ?/S', '$1', $token);
        $token = preg_replace('/_(\+|-)_/', ' $1 ', $token);
        return 'calc('. $this->registerPreservedToken($token) .')';
    }

    /**
     * Shortens all zero values for a set of safe properties
     * e.g. padding: 0px 1px; -> padding:0 1px
     * e.g. padding: 0px 0rem 0em 0.0pc; -> padding:0
     * @param string $css
     * @return string
     */
    private function shortenZeroValues($css)
    {
        $css = preg_replace(
            array(
                $this->shortenOneZeroesRegex,
                $this->shortenTwoZeroesRegex,
                $this->shortenThreeZeroesRegex,
                $this->shortenFourZeroesRegex
            ),
            array(
                '$1$2:0',
                '$1$2:$3 0',
                '$1$2:$3 $4 0',
                '$1$2:$3 $4 $5 0'
            ),
            $css
        );

        // Replace 0 0; or 0 0 0; or 0 0 0 0; with 0 for safe properties only.
        $css = preg_replace('/(margin|padding):0(?: 0){1,3}(;|\}| !)/Si', '$1:0$2', $css);

        // Replace 0 0 0; or 0 0 0 0; with 0 0 for background-position property.
        $css = preg_replace('/(background-position):0(?: 0){2,3}(;|\}| !)/Si', '$1:0 0$2', $css);

        return $css;
    }

    /**
     * Converts rgb() colors to HEX format.
     * @param $matches
     * @return string
     */
    private function rgbToHexCallback($matches)
    {
        $hexColors = Minify0_Utils::rgbToHex(explode(',', $matches[1]));
        return $this->getHexColorStringFromHexColorsList($hexColors, $matches[2]);
    }

    /**
     * Converts hsl() color to HEX format.
     * @param $matches
     * @return string
     */
    private function hslToHexCallback($matches)
    {
        $hslValues = explode(',', $matches[1]);
        $rgbColors = Minify0_Utils::hslToRgb($hslValues);
        $hexColors = Minify0_Utils::rgbToHex($rgbColors);
        return $this->getHexColorStringFromHexColorsList($hexColors, $matches[2]);
    }

    /**
     * Given a list of HEX colors and a terminator, a HEX color string is returned with the supplied terminator added
     * at the end.
     * @param array $hexColors
     * @param string $terminator
     * @return string
     */
    private function getHexColorStringFromHexColorsList($hexColors, $terminator)
    {
        // Fix for issue #2528093: restore space after rgb() or hsl() function in some cases
        if (!preg_match('/[ ,);}]/', $terminator)) {
            $terminator = ' '. $terminator;
        }

        return '#'. implode('', $hexColors) . $terminator;
    }

    /**
     * Compresses HEX color values of the form #AABBCC to #ABC or short color name.
     *
     * DOES NOT compress CSS ID selectors which match the above pattern (which would break things).
     * e.g. #AddressForm { ... }
     *
     * DOES NOT compress IE filters, which have hex color values (which would break things).
     * e.g. filter: chroma(color="#FFFFFF");
     *
     * DOES NOT compress invalid hex values.
     * e.g. background-color: #aabbccdd
     *
     * @param $matches
     * @return string
     */
    private function shortenHexColorsCallback($matches)
    {
        $isFilter = $matches[1] !== null && $matches[1] !== '';

        if ($isFilter) {
            // Restore, maintain case, otherwise filter will break
            $color = '#'. $matches[2] . $matches[3] . $matches[4] . $matches[5] . $matches[6] . $matches[7];
        } else {
            if (preg_match('/#([0-9a-f])(?:\1)([0-9a-f])(?:\2)([0-9a-f])(?:\3)/Si', $matches[0])) {
                // Compress.
                $hex = $matches[3] . $matches[5] . $matches[7];
            } else {
                // Non compressible color, restore.
                $hex = $matches[2] . $matches[3] . $matches[4] . $matches[5] . $matches[6] . $matches[7];
            }

            // lower case
            $hex = '#'. strtolower($hex);

            // replace Hex colors with shorter color names
            $color = array_key_exists($hex, $this->hexToNamedColorsMap) ? $this->hexToNamedColorsMap[$hex] : $hex;
        }

        return $matches[1] . $color . $matches[8];
    }

    /**
     * Shortens all named colors with a shorter HEX counterpart for a set of safe properties
     * e.g. white -> #fff
     * @param string $css
     * @return string
     */
    private function shortenNamedColorsCallback($matches)
    {
        return $matches[1] . $this->namedToHexColorsMap[strtolower($matches[2])] . $matches[3];
    }
}
