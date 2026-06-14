<?php

#
#
#
# Parsedown
# http://parsedown.org
#
# (c) Emanuil Rusev
# http://erusev.com
#
# For the full license information, view the LICENSE file that was distributed
# with this source code.
#
#
#

class Parsedown
{
    # ~

    const version = '1.8.0-beta-7';

    # ~

    function text($text)
    {
        $Elements = $this->textElements($text);

        # convert to markup
        $markup = $this->elements($Elements);

        # trim line breaks
        $markup = trim($markup, "\n");

        return $markup;
    }

    protected function textElements($text)
    {
        # make sure no definitions are set
        $this->DefinitionData = array();

        # Ensure $text is a string
        if (!is_string($text)) {
            if (is_array($text)) {
                $text = implode("\n", $text);
            } else {
                $text = (string)$text;
            }
        }

        # standardize line breaks
        $text = str_replace(array("\r\n", "\r"), "\n", $text);

        # remove surrounding line breaks
        $text = trim($text, "\n");

        # split text into lines
        $lines = explode("\n", $text);

        # iterate through lines to identify blocks
        return $this->linesElements($lines);
    }

    #
    # Setters
    #

    function setBreaksEnabled($breaksEnabled)
    {
        $this->breaksEnabled = (bool) $breaksEnabled;

        return $this;
    }

    function setMarkupEscaped($markupEscaped)
    {
        $this->markupEscaped = (bool) $markupEscaped;

        return $this;
    }

    function setUrlsLinked($urlsLinked)
    {
        $this->urlsLinked = (bool) $urlsLinked;

        return $this;
    }

    function setSafeMode($safeMode)
    {
        $this->safeMode = (bool) $safeMode;

        return $this;
    }

    function setStrictMode($strictMode)
    {
        $this->strictMode = (bool) $strictMode;

        return $this;
    }

    protected $breaksEnabled;
    protected $markupEscaped;
    protected $urlsLinked = true;
    protected $safeMode;
    protected $strictMode;

    #
    # Lines
    #

    protected function lines($text)
    {
        $Elements = $this->textElements($text);

        # convert to markup

        $markup = $this->elements($Elements);

        # trim line breaks

        $markup = trim($markup, "\n");

        return $markup;
    }

    protected function linesElements(array $lines)
    {
        $Elements = array();
        $CurrentBlock = null;

        foreach ($lines as $line)
        {
            if (chop($line) === '')
            {
                if (isset($CurrentBlock))
                {
                    $CurrentBlock['interrupted'] = true;
                }

                continue;
            }

            if (strpos($line, "\t") !== false)
            {
                $parts = explode("\t", $line);
                $line = array_shift($parts);

                foreach ($parts as $part)
                {
                    $shortage = 4 - mb_strlen($line, 'utf-8') % 4;

                    $line .= str_repeat(' ', $shortage);
                    $line .= $part;
                }
            }

            $indent = 0;

            while (isset($line[$indent]) and $line[$indent] === ' ')
            {
                $indent++;
            }

            $text = $indent > 0 ? substr($line, $indent) : $line;

            # ~

            $Line = array('body' => $line, 'indent' => $indent, 'text' => $text);

            # ~

            if (isset($CurrentBlock['continuable']))
            {
                $Block = $this->{'block'.$CurrentBlock['type'].'Continue'}($Line, $CurrentBlock);

                if (isset($Block))
                {
                    $CurrentBlock = $Block;

                    continue;
                }
                else
                {
                    if (method_exists($this, 'block'.$CurrentBlock['type'].'Complete'))
                    {
                        $CurrentBlock = $this->{'block'.$CurrentBlock['type'].'Complete'}($CurrentBlock);
                    }

                    $CurrentBlock['complete'] = true;
                }
            }

            # ~

            $marker = $text[0];

            # ~

            $blockTypes = $this->unmarkedBlockTypes;

            if (isset($this->BlockTypes[$marker]))
            {
                foreach ($this->BlockTypes[$marker] as $blockType)
                {
                    $blockTypes []= $blockType;
                }
            }

            #
            # ~

            foreach ($blockTypes as $blockType)
            {
                // Check if method exists before calling
                $method = 'block'.$blockType;
                if (!method_exists($this, $method)) {
                    continue;
                }
                $Block = $this->$method($Line, $CurrentBlock);

                if (isset($Block))
                {
                    $Block['type'] = $blockType;

                    if ( ! isset($Block['identified']))
                    {
                        $Blocks []= $CurrentBlock;

                        $Block['identified'] = true;
                    }

                    if (method_exists($this, 'block'.$blockType.'Continue'))
                    {
                        $Block['continuable'] = true;
                    }

                    $CurrentBlock = $Block;

                    continue 2;
                }
            }

            # ~

            if (isset($CurrentBlock) and ! isset($CurrentBlock['type']) and ! isset($CurrentBlock['interrupted']))
            {
                $CurrentBlock['element']['text'] .= "\n".$text;
            }
            else
            {
                $Blocks []= $CurrentBlock;

                $CurrentBlock = $this->paragraph($Line);

                $CurrentBlock['identified'] = true;
            }

            unset($CurrentBlock['interrupted']);
        }

        # ~

        if (isset($CurrentBlock['continuable']) and method_exists($this, 'block'.$CurrentBlock['type'].'Complete'))
        {
            $CurrentBlock = $this->{'block'.$CurrentBlock['type'].'Complete'}($CurrentBlock);
        }

        # ~

        $Blocks []= $CurrentBlock;

        unset($Blocks[0]);

        # ~

        $Elements = array();

        foreach ($Blocks as $Block)
        {
            if (isset($Block['hidden']))
            {
                continue;
            }

            $Elements []= $Block['element'];
        }

        return $Elements;
    }

    protected $BlockTypes = array(
        '#' => array('Header'),
        '*' => array('Rule', 'List'),
        '+' => array('List'),
        '-' => array('SetextHeader', 'Table', 'Rule', 'List'),
        '0' => array('List'),
        '1' => array('List'),
        '2' => array('List'),
        '3' => array('List'),
        '4' => array('List'),
        '5' => array('List'),
        '6' => array('List'),
        '7' => array('List'),
        '8' => array('List'),
        '9' => array('List'),
        ':' => array('Table'),
        '<' => array('Markup'),
        '=' => array('SetextHeader'),
        '>' => array('Quote'),
        '[' => array('Reference'),
        '_' => array('Rule'),
        '`' => array('FencedCode'),
        '|' => array('Table'),
        '~' => array('FencedCode'),
    );

    protected $unmarkedBlockTypes = array(
        'Code',
    );

    #
    # Block: Code
    #

    protected function blockCode($Line, $Block = null)
    {
        if (isset($Block) and isset($Block['type']) and $Block['type'] === 'Paragraph' and ! isset($Block['interrupted']))
        {
            return;
        }

        if ($Line['indent'] >= 4)
        {
            $text = substr($Line['body'], 4);

            $Block = array(
                'element' => array(
                    'name' => 'pre',
                    'handler' => 'element',
                    'text' => array(
                        'name' => 'code',
                        'text' => $text,
                    ),
                ),
            );

            return $Block;
        }
    }

    protected function blockCodeContinue($Line, $Block)
    {
        if ($Line['indent'] >= 4)
        {
            $Block['element']['text']['text'] .= "\n".substr($Line['body'], 4);

            return $Block;
        }

        if (chop($Line['body']) === '')
        {
            $Block['element']['text']['text'] .= "\n";

            return $Block;
        }
    }

    protected function blockCodeComplete($Block)
    {
        $text = $Block['element']['text']['text'];

        $Block['element']['text']['text'] = $text;

        return $Block;
    }

    #
    # Block: Fenced Code
    #

    protected function blockFencedCode($Line)
    {
        $marker = $Line['text'][0];

        $openerLength = strspn($Line['text'], $marker);

        if ($openerLength < 3)
        {
            return;
        }

        $infostring = trim(substr($Line['text'], $openerLength), " \t");

        if (strpos($infostring, '`') !== false)
        {
            return;
        }

        $Element = array(
            'name' => 'code',
            'text' => '',
        );

        if ($infostring !== '')
        {
            $Element['attributes'] = array('class' => "language-$infostring");
        }

        $Block = array(
            'char' => $marker,
            'openerLength' => $openerLength,
            'element' => array(
                'name' => 'pre',
                'handler' => 'element',
                'text' => $Element,
            ),
        );

        return $Block;
    }

    protected function blockFencedCodeContinue($Line, $Block)
    {
        if (isset($Block['complete']))
        {
            return;
        }

        if ($Line['text'][0] === $Block['char'])
        {
            $closerLength = strspn($Line['text'], $Block['char']);

            if ($closerLength >= $Block['openerLength'])
            {
                $Block['element']['text']['text'] = substr($Block['element']['text']['text'], 1);

                $Block['complete'] = true;

                return $Block;
            }
        }

        $Block['element']['text']['text'] .= "\n".$Line['body'];

        return $Block;
    }

    protected function blockFencedCodeComplete($Block)
    {
        return $Block;
    }

    #
    # Block: Header
    #

    protected function blockHeader($Line)
    {
        $level = strspn($Line['text'], '#');

        if ($level > 6)
        {
            return;
        }

        $text = trim($Line['text'], ' #');

        $Block = array(
            'element' => array(
                'name' => 'h'.$level,
                'text' => $text,
                'handler' => 'line',
            ),
        );

        return $Block;
    }

    #
    # Block: List
    #

    protected function blockList($Line, $Block = null)
    {
        list($name, $pattern) = $Line['text'][0] <= '-' ? array('ul', '[*+-]') : array('ol', '[0-9]+[.]');

        if (preg_match('/^('.$pattern.'[ ]+)(.*)/', $Line['text'], $matches))
        {
            $contentIndent = strlen($matches[1]);

            $Block = array(
                'indent' => $Line['indent'],
                'pattern' => $pattern,
                'element' => array(
                    'name' => $name,
                    'handler' => 'elements',
                ),
            );

            $Block['element']['text'] []= array(
                'name' => 'li',
                'handler' => 'elements',
                'text' => $this->linesElements(array($matches[2])),
            );

            return $Block;
        }
    }

    protected function blockListContinue($Line, array $Block)
    {
        if ($Line['indent'] === $Block['indent'] and preg_match('/^('.$Block['pattern'].'[ ]+)(.*)/', $Line['text'], $matches))
        {
            $Block['element']['text'] []= array(
                'name' => 'li',
                'handler' => 'elements',
                'text' => $this->linesElements(array($matches[2])),
            );

            return $Block;
        }

        if ($Line['indent'] > $Block['indent'])
        {
            $lastElement = &$Block['element']['text'][count($Block['element']['text']) - 1];

            $lastElement['text'] []= array(
                'name' => 'p',
                'text' => $Line['text'],
                'handler' => 'line',
            );

            return $Block;
        }
    }

    #
    # Block: Quote
    #

    protected function blockQuote($Line)
    {
        if (preg_match('/^>[ ]?(.*)/', $Line['text'], $matches))
        {
            $Block = array(
                'element' => array(
                    'name' => 'blockquote',
                    'handler' => 'lines',
                    'text' => (array) $matches[1],
                ),
            );

            return $Block;
        }
    }

    protected function blockQuoteContinue($Line, array $Block)
    {
        if ($Line['text'][0] === '>' and preg_match('/^>[ ]?(.*)/', $Line['text'], $matches))
        {
            $Block['element']['text'] []= $matches[1];

            return $Block;
        }

        if ( ! isset($Block['interrupted']))
        {
            $Block['element']['text'] []= $Line['text'];

            return $Block;
        }
    }

    #
    # Block: Rule
    #

    protected function blockRule($Line)
    {
        if (preg_match('/^([ ]{0,3})([-*_])(?:[ ]*\2){2,}[ ]*$/', $Line['body']))
        {
            $Block = array(
                'element' => array(
                    'name' => 'hr',
                ),
            );

            return $Block;
        }
    }

    #
    # Block: Setext Header
    #

    protected function blockSetextHeader($Line, array $Block = null)
    {
        if ( ! isset($Block) or !isset($Block['type']) or $Block['type'] !== 'Paragraph' or isset($Block['interrupted']))
        {
            return;
        }

        if ($Line['indent'] < 4 and chop($Line['text'], ' ') === '' and preg_match('/^(-+|=+)[ ]*$/', $Line['text'], $matches))
        {
            $level = $matches[1][0] === '=' ? 1 : 2;

            $Block['element']['name'] = 'h'.$level;
            $Block['element']['handler'] = 'line';

            return $Block;
        }
    }

    #
    # Block: Markup
    #

    protected function blockMarkup($Line)
    {
        if ($this->markupEscaped || $this->safeMode)
        {
            return;
        }

        if (preg_match('/^<(\w*)(?:[ ]*'.$this->regexHtmlAttribute.')*[ ]*(\/)?>/', $Line['text'], $matches))
        {
            $Block = array(
                'name' => $matches[1],
                'element' => array(
                    'rawHtml' => $Line['text'],
                    'allowRawHtmlInSafeMode' => true,
                ),
            );

            return $Block;
        }
    }

    protected function blockMarkupContinue($Line, array $Block)
    {
        if (isset($Block['closed']))
        {
            return;
        }

        $Block['element']['rawHtml'] .= "\n".$Line['body'];

        return $Block;
    }

    #
    # Block: Reference
    #

    #
    # Block: Table
    #

    protected function blockTable($Line, array $Block = null)
    {
        // Table support not fully implemented in this version
        // Return null to skip table parsing
        return null;
    }

    protected function blockReference($Line)
    {
        if (preg_match('/^\[(.+)\]:[ ]*(.+)$/', $Line['text'], $matches))
        {
            $id = strtolower($matches[1]);
            $url = $matches[2];

            $this->DefinitionData['Reference'][$id] = array(
                'url' => $url,
            );

            $Block = array(
                'hidden' => true,
            );

            return $Block;
        }
    }

    #
    # Block: Paragraph
    #

    protected function paragraph($Line)
    {
        $Block = array(
            'element' => array(
                'name' => 'p',
                'text' => $Line['text'],
                'handler' => 'line',
            ),
        );

        return $Block;
    }

    #
    # Inline Elements
    #

    protected $InlineTypes = array(
        '!' => array('Image'),
        '&' => array('SpecialCharacter'),
        '*' => array('Emphasis'),
        ':' => array('Url'),
        '<' => array('UrlTag', 'EmailTag', 'Markup'),
        '[' => array('Link'),
        '_' => array('Emphasis'),
        '`' => array('Code'),
        '~' => array('Strikethrough'),
        '\\' => array('EscapeSequence'),
    );

    protected $inlineMarkerList = '!*_&[:<`~\\';

    public function line($text, $nonNestables = array())
    {
        $markup = '';

        # $excerpt is based on the first occurrence of a marker

        while ($excerpt = strpbrk($text, $this->inlineMarkerList))
        {
            $marker = $excerpt[0];
            $markerPosition = strpos($text, $marker);

            $markup .= $this->textWithinInline($text, $markerPosition);

            $text = substr($text, $markerPosition);

            $Inline = null;

            foreach ($this->InlineTypes[$marker] as $inlineType)
            {
                if (in_array($inlineType, $nonNestables))
                {
                    continue;
                }

                $Inline = $this->{'inline'.$inlineType}($text);

                if (isset($Inline))
                {
                    if ($inlineType === 'Link')
                    {
                        $nonNestables[] = 'Link';
                    }

                    // Ensure Inline['element'] is an array before passing to element()
                    if (is_array($Inline['element']) && isset($Inline['element']['name'])) {
                        $markup .= $this->element($Inline['element']);
                    } else {
                        // If not a valid element, treat as text
                        $markup .= is_string($Inline['element']) ? htmlspecialchars($Inline['element'], ENT_NOQUOTES, 'UTF-8') : '';
                    }

                    $text = substr($text, $Inline['extent']);

                    continue 2;
                }
            }

            $markup .= $marker;
            $text = substr($text, 1);
        }

        $markup .= $this->textWithinInline($text);

        return $markup;
    }

    protected function textWithinInline($text, $markerPosition = null)
    {
        if ($markerPosition === null)
        {
            $text = $this->encodeSpecialCharacters($text);

            return $text;
        }

        $excerpt = substr($text, 0, $markerPosition);

        $excerpt = $this->encodeSpecialCharacters($excerpt);

        return $excerpt;
    }

    protected function inlineCode($text)
    {
        if (preg_match('/^`(.+?)`/', $text, $matches))
        {
            $text = $matches[1];

            $Inline = array(
                'extent' => strlen($matches[0]),
                'element' => array(
                    'name' => 'code',
                    'text' => $text,
                ),
            );

            return $Inline;
        }
    }

    protected function inlineEmailTag($text)
    {
        if (strpos($text, '>') !== false and preg_match('/^<((mailto:)?\S+?@\S+?)>/', $text, $matches))
        {
            $email = $matches[1];

            $Inline = array(
                'extent' => strlen($matches[0]),
                'element' => array(
                    'name' => 'a',
                    'text' => $email,
                    'attributes' => array(
                        'href' => 'mailto:'.$email,
                    ),
                ),
            );

            return $Inline;
        }
    }

    protected function inlineEmphasis($text)
    {
        // 支持 *斜体* / _斜体_ 以及 **粗体** / __粗体__
        if (preg_match('/^(\*{1,2}|_{1,2})(?=\S)(.+?[*_]*)(?<!\s)\1/', $text, $matches))
        {
            $marker = $matches[1];
            $len    = strlen($marker);

            $Inline = array(
                'extent' => strlen($matches[0]),
                'element' => array(
                    // 单个 * 或 _ 解析为 em，两个 ** 或 __ 解析为 strong
                    'name'    => $len === 1 ? 'em' : 'strong',
                    'handler' => 'line',
                    'text'    => $matches[2],
                ),
            );

            return $Inline;
        }
    }

    protected function inlineEscapeSequence($text)
    {
        if (isset($text[1]) and in_array($text[1], str_split($this->specialCharacters)))
        {
            $Inline = array(
                'element' => array('rawHtml' => $text[1]),
                'extent' => 2,
            );

            return $Inline;
        }
    }

    protected function inlineImage($text)
    {
        if (preg_match('/^!\[([^][]*)\]\(([^ ()]+)(?:[ ]+"(.*?)")?\)/', $text, $matches))
        {
            $Inline = array(
                'extent' => strlen($matches[0]),
                'element' => array(
                    'name' => 'img',
                    'attributes' => array(
                        'src' => $matches[2],
                        'alt' => $matches[1],
                    ),
                ),
            );

            if (isset($matches[3]))
            {
                $Inline['element']['attributes']['title'] = $matches[3];
            }

            return $Inline;
        }
    }

    protected function inlineLink($text)
    {
        if (preg_match('/^\[([^][]+)\]\(([^ ]+)(?: +"(.*?)")?\)/', $text, $matches))
        {
            $Inline = array(
                'extent' => strlen($matches[0]),
                'element' => array(
                    'name' => 'a',
                    'handler' => 'line',
                    'text' => $matches[1],
                    'attributes' => array(
                        'href' => $matches[2],
                    ),
                ),
            );

            if (isset($matches[3]))
            {
                $Inline['element']['attributes']['title'] = $matches[3];
            }

            return $Inline;
        }
    }

    protected function inlineMarkup($text)
    {
        if ($this->markupEscaped || $this->safeMode)
        {
            return;
        }

        if (preg_match('/^<\/?[^>]+>/', $text, $matches))
        {
            $Inline = array(
                'extent' => strlen($matches[0]),
                'element' => array(
                    'rawHtml' => $matches[0],
                    'allowRawHtmlInSafeMode' => true,
                ),
            );

            return $Inline;
        }
    }

    protected function inlineSpecialCharacter($text)
    {
        if (strpos($text, '&') === 0 and preg_match('/^&#?\w+;/', $text, $matches))
        {
            $Inline = array(
                'element' => array('rawHtml' => $matches[0]),
                'extent' => strlen($matches[0]),
            );

            return $Inline;
        }
    }

    protected function inlineStrikethrough($text)
    {
        if (preg_match('/^~~(?=\S)(.+?)(?<!\s)~~/', $text, $matches))
        {
            $Inline = array(
                'extent' => strlen($matches[0]),
                'element' => array(
                    'name' => 'del',
                    'handler' => 'line',
                    'text' => $matches[1],
                ),
            );

            return $Inline;
        }
    }

    protected function inlineUrl($text)
    {
        if ($this->urlsLinked and strpos($text, '://') !== false and preg_match('/^((https?|ftp):\/\/[^\s<]+[^<.,:;"\')\]\s])/', $text, $matches))
        {
            $Inline = array(
                'extent' => strlen($matches[1]),
                'element' => array(
                    'name' => 'a',
                    'text' => $matches[1],
                    'attributes' => array(
                        'href' => $matches[1],
                    ),
                ),
            );

            return $Inline;
        }
    }

    protected function inlineUrlTag($text)
    {
        if (preg_match('/^<((https?|ftp):\/\/[^\s]+)>/', $text, $matches))
        {
            $Inline = array(
                'extent' => strlen($matches[0]),
                'element' => array(
                    'name' => 'a',
                    'text' => $matches[1],
                    'attributes' => array(
                        'href' => $matches[1],
                    ),
                ),
            );

            return $Inline;
        }
    }

    #
    # ~
    #

    protected $DefinitionData;

    #
    # ~
    #

    protected $specialCharacters = '\\`*_{}[]()#+-.!>';

    protected function encodeSpecialCharacters($text)
    {
        if ($this->markupEscaped)
        {
            $text = htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
        }

        $regex = '/['.preg_quote($this->specialCharacters, '/').']/';

        return preg_replace_callback($regex, array($this, 'encodeSpecialCharacter'), $text);
    }

    protected function encodeSpecialCharacter($matches)
    {
        return '&#'.ord($matches[0]).';';
    }

    #
    # Element
    #

    protected function element(array $Element)
    {
        $markup = '<'.$Element['name'];

        if (isset($Element['attributes']))
        {
            foreach ($Element['attributes'] as $name => $value)
            {
                if ($value === null)
                {
                    continue;
                }

                $markup .= ' '.$name.'="'.htmlspecialchars($value, ENT_QUOTES, 'UTF-8').'"';
            }
        }

        if (isset($Element['text']))
        {
            $markup .= '>';

            if (isset($Element['handler']))
            {
                // Handle text - could be string or array
                $text = $Element['text'];
                
                // Check if text is already an array of elements (processed)
                // More robust check: verify if it's an array of element structures
                $isElementArray = false;
                if (is_array($text) && !empty($text)) {
                    // Check if first item looks like an element (has 'name' key)
                    if (isset($text[0]) && is_array($text[0]) && isset($text[0]['name'])) {
                        $isElementArray = true;
                    }
                }
                
                if ($isElementArray) {
                    // It's an array of elements, use elements() method directly
                    $markup .= $this->elements($text);
                } else {
                    // Handle based on handler type
                    if ($Element['handler'] === 'lines') {
                        // 'lines' handler expects array of strings
                        if (is_array($text)) {
                            // Ensure all items are strings
                            $textArray = array_map(function($item) {
                                return is_array($item) ? implode("\n", $item) : (string)$item;
                            }, $text);
                            $markup .= $this->lines($textArray);
                        } else {
                            // Convert string to array
                            $textArray = is_string($text) ? explode("\n", $text) : array((string)$text);
                            $markup .= $this->lines($textArray);
                        }
                    } elseif ($Element['handler'] === 'element') {
                        // 'element' handler expects an element array
                        if (is_array($text) && isset($text['name'])) {
                            // It's a valid element array
                            $markup .= $this->element($text);
                        } else {
                            // Invalid element, skip or treat as text
                            if (is_string($text)) {
                                $markup .= htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
                            }
                        }
                    } elseif ($Element['handler'] === 'elements') {
                        // 'elements' handler expects an array of elements
                        if (is_array($text)) {
                            // Filter to ensure all items are valid element arrays
                            $validElements = array_filter($text, function($item) {
                                return is_array($item) && isset($item['name']);
                            });
                            if (!empty($validElements)) {
                                $markup .= $this->elements(array_values($validElements));
                            }
                        }
                    } else {
                        // For 'line' and other handlers, ensure text is string
                        if (is_array($text)) {
                            // Convert array to string
                            $text = implode("\n", array_map(function($item) {
                                if (is_array($item)) {
                                    // If nested array, try to convert recursively
                                    return implode("\n", array_map('strval', $item));
                                }
                                return (string)$item;
                            }, $text));
                        } elseif (!is_string($text)) {
                            $text = (string)$text;
                        }
                        $markup .= $this->{$Element['handler']}($text);
                    }
                }
            }
            else
            {
                // Ensure text is a string before htmlspecialchars
                $text = $Element['text'];
                if (is_array($text)) {
                    $text = implode("\n", $text);
                } elseif (!is_string($text)) {
                    $text = (string)$text;
                }
                $markup .= htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
            }

            $markup .= '</'.$Element['name'].'>';
        }
        else
        {
            $markup .= ' />';
        }

        return $markup;
    }

    protected function elements(array $Elements)
    {
        $markup = '';

        foreach ($Elements as $Element)
        {
            // Ensure Element is an array
            if (!is_array($Element)) {
                // If it's a string, treat it as plain text
                if (is_string($Element)) {
                    $markup .= htmlspecialchars($Element, ENT_NOQUOTES, 'UTF-8');
                } else {
                    // Convert to string for other types
                    $markup .= htmlspecialchars((string)$Element, ENT_NOQUOTES, 'UTF-8');
                }
                $markup .= "\n";
                continue;
            }
            
            // Ensure Element has required 'name' key
            if (!isset($Element['name'])) {
                // If no name, try to process as text content
                if (isset($Element['text'])) {
                    $text = $Element['text'];
                    if (is_array($text)) {
                        // Recursively process array, but ensure it's actually an array of elements
                        // Filter out non-array items to prevent type errors
                        $validElements = array_filter($text, function($item) {
                            return is_array($item);
                        });
                        if (!empty($validElements)) {
                            $markup .= $this->elements(array_values($validElements));
                        } else {
                            // If no valid elements, treat as string array
                            $markup .= htmlspecialchars(implode("\n", array_map('strval', $text)), ENT_NOQUOTES, 'UTF-8');
                        }
                    } else {
                        $markup .= htmlspecialchars((string)$text, ENT_NOQUOTES, 'UTF-8');
                    }
                }
                $markup .= "\n";
                continue;
            }
            
            // Final safety check before calling element()
            if (!is_array($Element) || !isset($Element['name'])) {
                // Skip invalid elements
                continue;
            }
            
            $markup .= $this->element($Element);
            $markup .= "\n";
        }

        return $markup;
    }

    #
    # Regex
    #

    protected $regexHtmlAttribute = '[a-zA-Z_:][\w:.-]*(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'=<>`]+))?';
}
