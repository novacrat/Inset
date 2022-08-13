<?php
// ***************************************************
// **************** Petrov's Emperor *****************
// ***************************************************
// <c> Coryright 2010 Petrov's Studio | www.petrovs.ru
// ---------------------------------------------------
namespace Novacrat\Inset;


class Inset
{
    // tokens types
    const TOKEN_DATA        = 1;
    const TOKEN_VAR         = 2;
    const TOKEN_BLOCK       = 3;
    const TOKEN_COMMENT     = 4;

    // chips
    const CHIP_SPACERS      = 0x01;
    const CHIP_NUMBER       = 0x02;
    const CHIP_INT          = 0x04;
    const CHIP_STRING       = 0x08;
    const CHIP_NAME         = 0x10;
    const CHIP_COMPOUNDNAME = 0x20;
    const CHIP_BLOCKTYPE    = 0x40;

    // syntax
    const SNTX_OPENBLOCK    = '{[';	// block open, must be non-word characters
    const SNTX_CLOSEBLOCK   = ']}';	// block close, must be non-word characters
    const SNTX_OPENVAR      = '{{';	// var open, must be non-word characters
    const SNTX_CLOSEVAR     = '}}';	// var close, must be non-word characters
    const SNTX_OPENCOMMENT  = '{#';	// comment open, must be non-word characters
    const SNTX_CLOSECOMMENT = '#}';	// comment close, must be non-word characters
    const SNTX_DLM_EQUIV    = 'as';	// equiv delimiter
    const SNTX_DLM_IN       = 'in';	// in a sequence delimiter
    const SNTX_DLM_ATTR     = '|'; // attribute delimiter, must be a single non-word character
    const SNTX_DLM_VALUE    = ':'; // value delimiter, must be a single non-word character
    const SNTX_DLM_COMPOUND = '.'; // compound name delimiter, must be a single non-word character
    const SNTX_DLM_ENUM     = ','; // enumeration delimiter, must be a single non-word character
    const SNTX_OPENPARAMS   = '('; // parameters opening, must be a single non-word character
    const SNTX_CLOSEPARAMS  = ')'; // parameters opening, must be a single non-word character
    const SNTX_BREAKERS     = "\t\r\n\0\x0B";
    const SNTX_SPACERS      = " \t\r\n\x0B";

    // regular expressions
    const REGEX_SPACERS     = '/^\s+/';
    const REGEX_NUMBER      = '/^[0-9]+(?:(?:\.|,)[0-9]+)?/';
    const REGEX_INTEGER     = '/^[0-9]+/';
    const REGEX_STRING      = '/^(?: "(?:[^"\\\\]*(?:\\\\.[^"\\\\]*)*)" | \'(?:[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*)\' )/smx';
    const REGEX_NAME        = '/^[A-Za-z_][A-Za-z0-9_]*/';
    const REGEX_COMPOUNDNAME = '/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)*/'; // uses compound name delimiter
    const REGEX_BLOCKTYPE   = '/^(?:meta|if|elseif|else|endif|slot|for|endfor|embed|with|endwith)\W/';

    private $_code = null;
    private $_code_end = null;
    private $_last_chip = null;
    private $_last_iteration = null;
    private $_last_keyname = null;
    private $_is_rendering_now = false;
    private $_unbreaking = true;
    private $_embed_external = false;

    /**
     * @var array|null Inset's token.
     * NULL means that tokenization was not performed.
     */
    protected $tokens = null;
    /**
     * @var string|null Inset's source code filename.
     * NULL means that source code was not loaded.
     */
    protected $filename = null;
    /**
     * @var Inset|null Inset's parent inset instance
     */
    protected $parent_inset = null;

    /** @var \emperor\eddy\Entry|null Inset's meta, retrieved in last rendering */
    protected $meta = null;
    /** @var array Inset's slots, filled in last rendering */
    protected $slots = array();
    /** @var array Embeded insets */
    protected $insets = array();
    /** @var array Inset's labels values, which are set by sequences */
    protected $label_layers = array();

    /** @var array Inset's variables */
    protected $vars = array();
    /** @var array Inset's fillers for slots */
    protected $fillers = array();

    protected $rendered_insets = array();

    /**
     * Creates inset's instance. Automatically load source code if filename
     * is specified and not NULL, creates empty inset otherwise.
     * @param string|null $filename
     * @param Inset|null $parent_inset
     */
    public function __construct($filename = null, array $vars = null, Inset $parent_inset = null)
    {
        if ($filename !== null) $this->load($filename);
        empty($vars) || $this->setVars($vars);
        $this->parent_inset = $parent_inset;
    }

    /**
     * Loads specified file containing source code to the inset's instance,
     * and tokenize it. Clears variables ans fillers, if needed. Throws an
     * exception if source code file cannot be read.
     * @param string $filename
     * @param bool $clear
     * @throws \Exception If file cannot be read
     */
    public function load($filename, $clear = true)
    {
        // reset
        $this->_code	  = null;
        $this->_code_end  = null;
        $this->_last_chip = null;
        $this->tokens	  = null;
        $this->filename	  = null;
        $this->meta		  = null;
        $this->slots	  = array();
        $this->insets	  = array();
        $this->label_layers = array();

        // clear variables ans fillers, if needed
        if ($clear) {
            $this->clearVars();
            $this->clearFillers();
        }

        // retrieve real path and check file
        $this->filename = realpath($filename);
        if (!$this->filename || !is_file($this->filename) || !is_readable($this->filename))
            throw new \Exception("Cannot read inset\'s code file $filename");

        // tokenize
        $this->_tokenize();
    }

    /**
     * Sets the Inset to render with or without removal of the breaker symbols
     * around the Inset's tokens.
     * @param bool $unbreaking
     */
    public function setUnbreaking($unbreaking = true)
    {
        $this->_unbreaking = (bool) $unbreaking;
    }

    /**
     * Indicates whether the Inset is set to render unbreaking.
     * @return bool
     */
    public function isUnbreaking()
    {
        return $this->_unbreaking;
    }

    /**
     * Sets the Inset permission to embed external inset files.
     * @param bool $embed_external
     */
    public function setEmbedExternal($embed_external = true)
    {
        $this->_embed_external = (bool) $embed_external;
    }

    /**
     * Indicates whether the Inset is allowed to embed external inset files.
     * @return bool
     */
    public function isEmbedExternal()
    {
        return $this->_embed_external;
    }


    // Tokenization

    /**/
    private function _tokenSpacer(&$pos)
    {
        $spacer_found = false;
        while (strpos(self::SNTX_SPACERS, $this->_code[$pos]) !== false) {
            ++$pos; $spacer_found = true;
        }
        return $spacer_found;
    }

    /**/
    private function _tokenString(&$pos, $wanted, $skip_spacers = true)
    {
        if ($skip_spacers) {
            while (strpos(self::SNTX_SPACERS, $this->_code[$pos]) !== false) ++$pos;
        }
        if (substr($this->_code, $pos, strlen($wanted)) !== $wanted) {
            return false;
        } else {
            $pos += strlen($wanted);
            return true;
        }
    }

    /**/
    private function _tokenChip(&$pos, $wanted, $skip_spacers = true)
    {
        if ($skip_spacers) {
            while (strpos(self::SNTX_SPACERS, $this->_code[$pos]) !== false) ++$pos;
        }

        $match = null;
        $ret = null;
        $code = substr($this->_code, $pos);
        $this->_last_chip = null;

        // number
        if ( $wanted & self::CHIP_NUMBER && preg_match(self::REGEX_NUMBER, $code, $match) )
        {
            $this->_last_chip = self::CHIP_NUMBER;
            $ret = 0 + strtr($match[0], ',', '.');
        }
        // integer
        elseif ( $wanted & self::CHIP_INT && preg_match(self::REGEX_INTEGER, $code, $match) )
        {
            $this->_last_chip = self::CHIP_INT;
            $ret = (int) $match[0];
        }
        // string
        elseif ( $wanted & self::CHIP_STRING && preg_match(self::REGEX_STRING, $code, $match) )
        {
            $this->_last_chip = self::CHIP_STRING;
            $ret = substr($match[0], 1, -1);
        }
        // compound name
        elseif ( $wanted & self::CHIP_COMPOUNDNAME && preg_match(self::REGEX_COMPOUNDNAME, $code, $match) )
        {
            $this->_last_chip = self::CHIP_COMPOUNDNAME;
            $ret = \emperor\array_explode(self::SNTX_DLM_COMPOUND, $match[0]);
            if (count($ret) === 1) { $ret = $ret[0];}
        }
        // name
        elseif ( $wanted & self::CHIP_NAME && preg_match(self::REGEX_NAME, $code, $match) )
        {
            $this->_last_chip = self::CHIP_NAME;
        }
        // blocktype
        elseif ( $wanted & self::CHIP_BLOCKTYPE && preg_match(self::REGEX_BLOCKTYPE, $code, $match) )
        {
            $this->_last_chip = self::CHIP_BLOCKTYPE;
            $match[0] = substr($match[0], 0, -1);
        }
        // spacers
        elseif ( $wanted & self::CHIP_SPACERS && preg_match(self::REGEX_SPACERS, $code, $match) )
        {
            $this->_last_chip = self::CHIP_SPACERS;
        }

        // found match?
        // return it, or return null
        if (count($match) === 0 || $match === null) {
            return null;
        } else {
            $pos += strlen($match[0]);
            return ($ret === null)? $match[0] : $ret;
        }
    }

    private function _tokenVariable(&$pos)
    {
        // check a chip, it must be a name or a content
        $content = $this->_tokenChip($pos, self::CHIP_COMPOUNDNAME | self::CHIP_NUMBER | self::CHIP_STRING);
        if ($content === null) return false; // no chip!

        // chip is name? operate over it
        if ($this->_last_chip === self::CHIP_COMPOUNDNAME) {
            $name = $content;
            $content = null;
        } else {
            $name = null;
        }

        // look for filters
        $filters = $this->_tokenFilters($pos);

        // return new tokenized variable
        return array(
            'name'		=> $name,
            'content'	=> $content,
            'filters'	=> $filters
        );
    }

    /**/
    private function _tokenFilters(&$pos)
    {
        $filters = array();
        while ($this->_tokenString($pos, self::SNTX_DLM_ATTR))
        {
            // take filter's name
            $filter_name = $this->_tokenChip($pos, self::CHIP_NAME);
            if ($filter_name === null) return false; // no filter's name

            // does filter have params?
            if ($this->_tokenString($pos, self::SNTX_OPENPARAMS)) {
                // retrieve params
                $params = array();
                do {
                    $param = $this->_tokenVariable($pos);
                    // parameter not found? than break in the first iteration, or return FALSE in others
                    if ($param === null) if (empty($params)) break; else return false; // no contnet!
                    // append parameter
                    $params[] = $param;
                }
                while ($this->_tokenString($pos, self::SNTX_DLM_ENUM));
                if (empty($params)) $params = null;

                // look for parameters closing
                if (!$this->_tokenString($pos, self::SNTX_CLOSEPARAMS)) return false;
            }
            // filter without parameters
            else {
                $params = null;
            }

            // append filter
            $filters[] = array($filter_name, $params);
        }
        return $filters;
    }

    /**/
    private function _tokenizeVar($pos)
    {
        $pos_next = $pos + strlen(self::SNTX_OPENVAR);

        // retrieve variable
        $var = $this->_tokenVariable($pos_next);
        if (!$var) return false; // no variable!

        // look for closing
        if ($this->_tokenString($pos_next, self::SNTX_CLOSEVAR)) {
            return array(
                'token'		=> self::TOKEN_VAR,
                'begin'		=> $pos, 'end' => $pos_next
            ) + $var;
        } else {
            return false;
        }
    }

    /**/
    private function _tokenizeBlock($pos)
    {
        $pos_next = $pos + strlen(self::SNTX_OPENBLOCK);

        // check a blocktype
        $type = $this->_tokenChip($pos_next, self::CHIP_BLOCKTYPE);
        if ($type === null) return false; // no type!

        // operate type
        $ret = array();
        switch ($type) {

            // [meta]
            // Meta-token contains "record", that is a EDDY inside the token.
            case 'meta':
                $ret['record'] = \emperor\eddy\Parser::parse(
                    $this->_code, $pos_next,
                    \emperor\eddy\OPTION_ALLOW_IDS |
                    \emperor\eddy\OPTION_LOWER_IDS |
                    \emperor\eddy\OPTION_NO_FLANKING |
                    \emperor\eddy\OPTION_EMBED
                );
                $pos_next = \emperor\eddy\Parser::getEndPos();
                break;

            // [slot]
            // Slot-token contains a name (not a compound name!)
            case 'slot':
                // look for slot identifier
                $name = $this->_tokenChip($pos_next, self::CHIP_INT | self::CHIP_NAME | self::CHIP_STRING );
                if ($name === null) return false; // no name!

                $ret = array('identifier' => $name);
                break;

            // [if]
            // If-token contains "option", that can be null or filled with value
            case 'if':
                // look for name
                $name = $this->_tokenChip($pos_next, self::CHIP_COMPOUNDNAME);
                if ($name === null) return false; // no name!
                // look for name filters
                $filters = $this->_tokenFilters($pos_next);

                $option = null;
                // was value delimiter found? than take an option
                if ($this->_tokenString($pos_next, self::SNTX_DLM_VALUE)) {
                    $option = $this->_tokenVariable($pos_next);
                    if (!$option) return false; // no option!
                }

                $ret = array('name' => $name, 'filters' => $filters, 'option' => $option);
                break;

            // [elseif]
            // Elseif-token is like if-token, but must has an option
            case 'elseif':
                // look for name
                $name = $this->_tokenChip($pos_next, self::CHIP_COMPOUNDNAME);
                if ($name === null) return false; // no name!
                // look for name filters
                $filters = $this->_tokenFilters($pos_next);

                // look for delimiter and than option
                if (!$this->_tokenString($pos_next, self::SNTX_DLM_VALUE)) return false; // no delimiter
                $option = $this->_tokenVariable($pos_next);
                if (!$option) return false; // no option!

                $ret = array('name' => $name, 'filters' => $filters, 'option' => $option);
                break;

            // [for]
            // For-token contains "label", that represents a value for a single of the sequence
            case 'for':
                // look for label
                $label = $this->_tokenChip($pos_next, self::CHIP_NAME);
                if ($label === null) return false; // no label!
                // look for 'in', and than a spacer (space or tab) after it
                if (!$this->_tokenString($pos_next, self::SNTX_DLM_IN)) return false; // no 'in'!
                if (!$this->_tokenSpacer($pos_next)) return false; // no spacer after 'in'!

                // look for variable
                $var = $this->_tokenVariable($pos_next);
                if (!$var) return false; // no variable!

                $ret = $var + array('label' => $label);
                break;

            // [embed]
            // Embed-token contains filename and labels
            case 'embed':
                // look for embed filename
                $filename = $this->_tokenChip($pos_next, self::CHIP_STRING );
                if ($filename === null) return false; // no filename!

                // look for variables labels
                $labels = array();
                // was value delimiter found? than take an equives
                if ($this->_tokenString($pos_next, self::SNTX_DLM_VALUE))
                {
                    do {
                        // retrieve label (and delimiter than)
                        $label = $this->_tokenChip($pos_next, self::CHIP_NAME);
                        if ($label === null) return false; // no equivname!
                        if (!$this->_tokenString($pos_next, self::SNTX_DLM_EQUIV)) return false; // no equiv delimiter
                        if (!$this->_tokenSpacer($pos_next)) return false; // no spacer after equiv delimiter!

                        // look for variable
                        $var = $this->_tokenVariable($pos_next);
                        if (!$var) return false; // no variable!

                        // append equiv pair
                        $labels[$label] = $var + array('label' => $label);
                    }
                    while ($this->_tokenString($pos_next, self::SNTX_DLM_ENUM));
                }

                $ret = array('filename' => $filename, 'labels' => $labels);
                break;

            // [with]
            // With-token contains labels
            case 'with':
                // retrieve enomerious labels
                $labels = array();
                do {
                    // retrieve label (and delimiter than)
                    $label = $this->_tokenChip($pos_next, self::CHIP_NAME);
                    if ($label === null) return false; // no equivname!
                    if (!$this->_tokenString($pos_next, self::SNTX_DLM_EQUIV)) return false; // no equiv delimiter
                    if (!$this->_tokenSpacer($pos_next)) return false; // no spacer after equiv delimiter!

                    // look for variable
                    $var = $this->_tokenVariable($pos_next);
                    if (!$var) return false; // no variable!

                    // append labels pair
                    $labels[$label] = $var + array('label' => $label);
                }
                while ($this->_tokenString($pos_next, self::SNTX_DLM_ENUM));

                $ret = array('labels' => $labels);
                break;

            // nothing more to check for all single blocks,
            // e.g. [else], [endif], [endfor]
            default:
                break;
        }

        // look for closing
        if ($this->_tokenString($pos_next, self::SNTX_CLOSEBLOCK)) {
            $token = array(
                'token' => self::TOKEN_BLOCK,
                'begin' => $pos, 'end' => $pos_next,
                'type'	=> $type,
            );
            //foreach ($ret as $key => $val) $token[$key] = $val;
            $token += $ret;
            return $token;
        } else {
            return false;
        }
    }

    /**/
    private function _tokenizeComment($pos)
    {
        $pos_next = $pos + strlen(self::SNTX_OPENCOMMENT);

        // look for closing
        if ( ($pos_next = strpos($this->_code, self::SNTX_CLOSECOMMENT, $pos_next)) !== false ) {
            $pos_next += strlen(self::SNTX_CLOSECOMMENT);
            return array(
                'token' => self::TOKEN_COMMENT,
                'begin' => $pos, 'end' => $pos_next
            );
        } else {
            return false;
        }
    }

    /**/
    private function _findTokens()
    {
        $this->tokens = array();
        $cursor = 0;
        $pos_v = true;
        $pos_b = true;
        $pos_c = true;

        // look for var and block tokens
        while (true) {
            // end of the code is reached?
            if ($cursor >= $this->_code_end) break;

            if ($pos_v !== false) $pos_v = strpos($this->_code, self::SNTX_OPENVAR, $cursor);
            if ($pos_b !== false) $pos_b = strpos($this->_code, self::SNTX_OPENBLOCK, $cursor);
            if ($pos_c !== false) $pos_c = strpos($this->_code, self::SNTX_OPENCOMMENT, $cursor);

            // seems like only a data left upto the end of code
            if ($pos_v === false && $pos_b === false && $pos_c === false) break;

            // seems like we have a var's opening
            if ( $pos_v !== false && ($pos_b === false || $pos_b > $pos_v) && ($pos_c === false || $pos_c > $pos_v) ) {
                $token = $this->_tokenizeVar($pos_v);
                $cursor = $pos_v;
            }
            // seems like we have a block's opening
            elseif ( $pos_b !== false && ($pos_c === false || $pos_c > $pos_b) ) {
                $token = $this->_tokenizeBlock($pos_b);
                $cursor = $pos_b;
            }
            // seems like we have a comment opening
            else {
                $token = $this->_tokenizeComment($pos_c);
                $cursor = $pos_c;
            }

            if ($token) {
                $this->tokens[$cursor] = $token;
                $cursor = $token['end'];
            } else {
                ++$cursor;
            }
        }

        // fill data tokens
        $cursor = 0;
        $points = array();
        foreach ($this->tokens as $pos => $token) {
            if ($pos > $cursor) $points[$cursor] = $pos;
            $cursor = $token['end'];
        }
        if ($cursor < $this->_code_end) $points[$cursor] = $this->_code_end;
        foreach ($points as $begin => $end) {
            $this->tokens[$begin] = array(
                'token'	=> self::TOKEN_DATA,
                'begin'	=> $begin, 'end' => $end,
                'content' => substr($this->_code, $begin, $end-$begin)
            );
        }

        ksort($this->tokens);
    }

    /**/
    private function _bindTokens()
    {
        $i = 0;
        $openings = array();
        $badblocks = array();

        // bind tokens and find bad blocks
        while ($i < $this->_code_end)
        {
            if ($this->tokens[$i]['token'] === self::TOKEN_BLOCK)
            {
                switch ($this->tokens[$i]['type'])
                {
                    /// if
                    case 'if':
                        $openings[] = $i;
                        break;

                    /// elseif
                    case 'elseif':
                        // [if] or [elseif] opened and has the same name?
                        if ( ($open = end($openings)) !== false
                            and $this->tokens[$open]['type'] === 'if' || $this->tokens[$open]['type'] === 'elseif'
                            and $this->tokens[$open]['name'] === $this->tokens[$i]['name'] )
                        {
                            $this->tokens[$i]['before'] = $open;
                            $this->tokens[$open]['next'] = $i;
                            $openings[key($openings)] = $i;
                        }
                        // this is unexpected block
                        else $badblocks[] = $i;
                        break;

                    /// else
                    case 'else':
                        // [if] or [elseif] opened?
                        if ( ($open = end($openings)) !== false
                            and $this->tokens[$open]['type'] === 'if' || $this->tokens[$open]['type'] === 'elseif' )
                        {
                            $this->tokens[$i]['before'] = $open;
                            $this->tokens[$open]['next'] = $i;
                            $openings[key($openings)] = $i;
                        }
                        // this is unexpected block
                        else $badblocks[] = $i;
                        break;

                    /// endif
                    case 'endif':
                        // [if] or [elseif] or [else] opened?
                        if ( ($open = end($openings)) !== false
                            and $this->tokens[$open]['type'] === 'if' || $this->tokens[$open]['type'] === 'elseif' || $this->tokens[$open]['type'] === 'else' )
                        {
                            $this->tokens[$i]['before'] = $open;
                            $this->tokens[$open]['next'] = $i;
                            array_pop($openings);
                        }
                        // this is unexpected block
                        else $badblocks[] = $i;
                        break;

                    /// for
                    case 'for':
                        $openings[] = $i;
                        break;

                    /// endfor
                    case 'endfor':
                        // [for] opened?
                        if ( ($open = end($openings)) !== false
                            and $this->tokens[$open]['type'] === 'for' )
                        {
                            $this->tokens[$i]['before'] = $open;
                            $this->tokens[$open]['next'] = $i;
                            array_pop($openings);
                        }
                        // this is unexpected block
                        else $badblocks[] = $i;
                        break;

                    /// embed
                    case 'embed':
                        // retrieve canonicalized filename
                        $filename = null;
                        if ($this->isEmbedExternal() || !\emperor\path_has_backwards($this->tokens[$i]['filename'])) {
                            $filename = realpath( dirname($this->filename) . '/' . $this->tokens[$i]['filename'] );
                            if (!is_file($filename) || !is_readable($filename)) $filename = null;
                        }

                        // filename retrieved successfully?
                        if ($filename !== null) {
                            // append new inset, if it has not been appended already
                            if (!isset($this->insets[$filename])) {
                                $this->insets[$filename] = new Inset($filename, array(), $this);
                            }
                            // change token filename to a canonicalized one
                            $this->tokens[$i]['filename'] = $filename;
                        }
                        // file could not be read
                        else {
                            $this->tokens[$i]['token'] = self::TOKEN_COMMENT; // just skip embed-tag like a comment!
                        }
                        break;

                    /// with
                    case 'with':
                        $openings[] = $i;
                        break;

                    /// endwith
                    case 'endwith':
                        // [with] opened?
                        if ( ($open = end($openings)) !== false
                            and $this->tokens[$open]['type'] === 'with' )
                        {
                            //$this->tokens[$i]['before'] = $open;
                            $this->tokens[$open]['next'] = $i;
                            array_pop($openings);
                        }
                        // this is unexpected block
                        else $badblocks[] = $i;
                        break;


                    // all single blocks,
                    // e.g. [meta], [slot]
                    default:
                        break;
                }
            }
            $i = $this->tokens[$i]['end'];
        }

        // turn badblocks into a data tokens
        foreach ($badblocks as $i) {
            $this->tokens[$i] = array(
                'token' => self::TOKEN_DATA,
                'begin' => $this->tokens[$i]['begin'], 'end' => $this->tokens[$i]['end'],
                'content' => substr($this->_code, $this->tokens[$i]['begin'], $this->tokens[$i]['end'] - $this->tokens[$i]['begin'])
            );
        }

        // autoclose openings, if needed
        if (count($openings) > 0) {
            $open = $this->_code_end;
            $openings = array_reverse($openings);
            foreach ($openings as $i) {
                switch ($this->tokens[$i]['type'])
                {
                    case 'if':
                    case 'elseif':
                    case 'else':
                        $this->tokens[$i]['next'] = $open;
                        $this->tokens[$open] = array(
                            'token' => self::TOKEN_BLOCK,
                            'begin' => $open, 'end' => $open + 1,
                            'type' => 'endif',
                            'before' => $i
                        );
                        break;

                    case 'for':
                        $this->tokens[$i]['next'] = $open;
                        $this->tokens[$open] = array(
                            'token' => self::TOKEN_BLOCK,
                            'begin' => $open, 'end' => $open + 1,
                            'type' => 'endfor',
                            'before' => $i
                        );
                        break;

                    case 'with':
                        $this->tokens[$i]['next'] = $open;
                        $this->tokens[$open] = array(
                            'token' => self::TOKEN_BLOCK,
                            'begin' => $open, 'end' => $open + 1,
                            'type' => 'endwith'
                            //'before' => $i
                        );
                        break;
                }
                ++$open;
                array_shift($openings);
            }
        }

        // clean tokens
        foreach ($this->tokens as $i => $token) {
            unset($this->tokens[$i]['begin']);
            unset($this->tokens[$i]['end']);
        }
    }

    /**/
    private function _tokenize()
    {
        // preset
        $this->_code = file_get_contents($this->filename);
        $this->_code_end = strlen($this->_code);
        $this->_last_chip = null;
        // perform
        $this->_findTokens();
        $this->_bindTokens();
        // reset
        $this->_code	  = null;
        $this->_code_end  = null;
        $this->_last_chip = null;
    }


    // Rendering

    /**
     * Renders the inset. Optionally renders the inset as embedded.
     * @param bool $embedded
     * @return string
     */
    public function render($embedded = false)
    {
        // unloaded inset consider to render as empty string
        if ($this->tokens === null) return '';

        // inset is rendering now?
        if ($this->_is_rendering_now) throw new \Exception(__CLASS__, 2, 'Recursive inset rendering occured', $this);
        $this->_is_rendering_now = true;
        $this->_rendered_insets = array();

        // reset
        $this->meta  = null;
        $this->slots = array();
        $openings	 = array();
        $sequences	 = array();
        $output		 = '';

        // walk through all tokens
        $token = reset($this->tokens);
        while ($token !== false)
        {
            $token_key = key($this->tokens);
            // need to close last opening?
            if ($token_key === end($openings)) {
                $this->_skipTokensUntil(key($openings));
                array_pop($openings);
            }
            // no closing needed
            else {

                // data token
                // ----------
                if ($token['token'] === self::TOKEN_DATA) {
                    $output .= $this->isUnbreaking()? trim($token['content'], self::SNTX_BREAKERS) : $token['content'];
                }

                // comment token
                // -------------
                elseif ($token['token'] === self::TOKEN_COMMENT) {
                    // do nothing
                }

                // variable token
                // --------------
                elseif ($token['token'] === self::TOKEN_VAR) {
                    $var = $this->_renderVariable($token);
                    if ($var instanceof Inset) {
                        $output .= $var->render(true);
                        $this->rendered_insets[] = $var;
                    } else {
                        $output .= \emperor\ensure_string($var);
                    }
                }

                // block token
                // -----------
                else {
                    switch ($token['type'])
                    {
                        case 'meta':
                            // sets meta record, or merges it with previous
                            if ($this->meta === null)
                                $this->meta = clone $token['record'];
                            else
                                $this->meta->merge($token['record']);
                            break;

                        case 'slot':
                            // fill the slot with content
                            $this->slots[] = $token['identifier'];
                            // filler found for the slot? fill the slot than!
                            if ( ($filler = $this->getFiller($token['identifier'])) !== null ) {
                                if (is_array($filler)) {
                                    foreach ($filler as $filler_item) {
                                        if ($filler_item instanceof Inset) {
                                            $output .= $filler_item->render(true);
                                            $this->rendered_insets[] = $filler_item;
                                        } else {
                                            $output .= $filler_item;
                                        }
                                        //$output .= is_string($filler_item)? $filler_item : $filler_item->render(true);
                                    }
                                } else {
                                    if ($filler instanceof Inset) {
                                        $output .= $filler->render(true);
                                        $this->rendered_insets[] = $filler;
                                    } else {
                                        $output .= $filler;
                                    }
                                    //$output .= is_string($filler)? $filler : $filler->render(true);
                                }
                            }
                            break;

                        case 'if':
                            //$value = $this->resolveName($token['name']);
                            //$value = $this->_renderFilters($value, $token['filters']);

                            $bunch_open = null;
                            $bt = $token; // bunch token
                            $bunch_final = $token_key;

                            // walk through all bunch
                            while ($bt !== false && $bt['type'] !== 'endif')
                            {
                                if ($bunch_open === null) {
                                    // determine bunch opening
                                    // check through variable openings
                                    if ($bt['type'] !== 'else') {
                                        // render value for bunch token
                                        $value = $this->_renderVariable($bt);
                                        // resolve option, either it is NULL or not
                                        $ok = ($bt['option'] === null)? (bool) $value : $value === $this->_renderVariable($bt['option']);
                                        // ok?
                                        if ($ok) $bunch_open = $bunch_final;
                                    }
                                    // come to else bunch, finally? ok than!
                                    else {
                                        $bunch_open = $bunch_final;
                                    }
                                }
                                // iterate to next bunch token
                                $bunch_final = $bt['next'];
                                $bt = $this->tokens[$bt['next']];
                            }

                            // was bunch opened?
                            if ($bunch_open !== null) {
                                // skip to opening
                                // add closing marker (where to close and where to skip after)
                                $this->_skipTokensUntil($bunch_open);
                                $openings[$bunch_final] = $this->tokens[$bunch_open]['next'];
                            } else {
                                // bunch wasn't opened! skip to final
                                $this->_skipTokensUntil($bunch_final);
                            }
                            break;

                        case 'for':
                            // need to initiate a new sequence?
                            end($sequences);
                            if ( ($last_sequence = key($sequences)) === false || $last_sequence !== $token_key )
                            {
                                // retrieve sequence array
                                $value = $this->_renderVariable($token);
                                if (!is_array($value)) $value = ($value)? array($value) : array();
                                $sequences[$token_key] = $value;
                                // register sequence label layer
                                $this->label_layers[] = array();
                            }
                            // sequence already was started, move on!
                            else {
                                next($sequences[$token_key]);
                            }

                            // next iteration available?
                            if (key($sequences[$token_key]) !== null) {
                                // register label's value for current iteration
                                end($this->label_layers);
                                $layer_key = key($this->label_layers);
                                $this->label_layers[$layer_key][$token['label']] = current($sequences[$token_key]);
                                // register current iteration
                                $layer_ite = $token['label'] . '#';
                                $layer_ite_key = $token['label'] . '@';
                                $this->label_layers[$layer_key][$layer_ite_key] = key($sequences[$token_key]);
                                if (isset($this->label_layers[$layer_key][$layer_ite])) {
                                    ++$this->label_layers[$layer_key][$layer_ite];
                                } else {
                                    $this->label_layers[$layer_key][$layer_ite] = 1;
                                }
                            }
                            // no further iterations, end sequence!
                            else {
                                array_pop($sequences);
                                array_pop($this->label_layers);
                                $this->_skipTokensUntil($token['next']);
                            }
                            break;

                        case 'endfor':
                            // get back to sequence beginning
                            $this->_revertTokensTo($token['before']);
                            $token = current($this->tokens);
                            continue 2;
                            break;

                        case 'embed':
                            // render embedded inset
                            $inset = $this->insets[$token['filename']];
                            foreach ($token['labels'] as $label) {
                                $inset->setVar($label['label'], $this->_renderVariable($label));
                            }
                            $output .= $inset->render(true);
                            // sets meta record, or merges it with previous
                            //var_dump($this->meta);
                            //var_dump($inset->getMeta());

                            if ($this->meta === null) $this->meta = $inset->getMeta();
                            else $this->meta->merge($inset->getMeta());

                            //var_dump($this->meta);
                            break;

                        case 'with':
                            // append new layer, and get its last key
                            $this->label_layers[] = array();
                            end($this->label_layers);
                            $label_key = key($this->label_layers);
                            // set all layers labels
                            foreach ($token['labels'] as $label) {
                                $this->label_layers[$label_key][$label['label']] = $this->_renderVariable($label);
                            }
                            break;

                        case 'endwith':
                            // delete last label layer
                            array_pop($this->label_layers);
                            break;

                        default:
                            // unexpected type
                            // do nothing
                            break;
                    }
                }

            }
            $token = next($this->tokens);
        }

        // TODO: resolve meta


        $this->_is_rendering_now = false;
        return $output;
    }

    /**
     * Resolves given name in current inset context, and returnes a value, that
     * given name is covers. Searches for value through current label layers,
     * inset's variables, parent inset. Given compound name is also allowed.
     * @param string $name
     * @return mixed|null
     */
    public function resolveName($name)
    {
        $this->_last_iteration = null;
        $this->_last_keyname = null;

        // don't we have a name? return empty string than
        if ($name === null)	return null;

        // retrieve target name (for compound names it would be the first chunk)
        $target_name = is_array($name)? reset($name) : $name;

        // search for value among the label layers
        $value = null;
        $layer = end($this->label_layers);
        while (key($this->label_layers) !== null) {
            if (isset($layer[$target_name])) {
                $value = $layer[$target_name];
                // retrieve iteration, if any
                $target_iteration = $target_name . '#';
                $target_iteration_key = $target_name . '@';
                $this->_last_iteration = isset($layer[$target_iteration])? $layer[$target_iteration] : null;
                $this->_last_keyname = isset($layer[$target_iteration_key])? $layer[$target_iteration_key] : null;
                break;
            }
            $layer = prev($this->label_layers);
        }

        // value not found yet?
        // than search for value among the variables
        if ($value === null && isset($this->vars[$target_name])) $value = $this->vars[$target_name];

        // value not found yet, and parent inset is available?
        // return parent inset resolvation than!
        if ($value === null && $this->parent_inset !== null) {
            return $this->parent_inset->resolveName($name);
        }

        // value still not found? return NULL than
        if ($value === null) return null;

        // name is compound? resolve the value!
        if (is_array($name)) {
            $name_chunk = next($name);
            while ($name_chunk !== false) {
                // resolve array
                if (is_array($value) && isset($value[$name_chunk])) {
                    $value = $value[$name_chunk];
                }
                // resolve object
                elseif (is_object($value)) {
                    $value = $value->$name_chunk;
                }
                // resolve context is incorrect
                // return NULL immediately
                else return null;

                $name_chunk = next($name);
            }
        }

        // return found value
        return $value;
    }

    /**/
    private function _skipTokensUntil($next)
    {
        $token = current($this->tokens);
        while ($token !== false && key($this->tokens) < $next) $token = next($this->tokens);
        return $token;
    }

    /**/
    private function _revertTokensTo($prev)
    {
        $token = current($this->tokens);
        while ($token !== false && key($this->tokens) > $prev) $token = prev($this->tokens);
        return $token;
    }

    /**/
    private function _renderVariable($var)
    {
        $this->_last_iteration = null;
        $this->_last_keyname = null;
        // variable does not holds any value? return NULL than
        if (!isset($var['name']) && !isset($var['content'])) return null;
        // retrieve value
        $value = ($var['name'] === null)? $var['content'] : $this->resolveName($var['name']);
        // apply filters
        $value = $this->_renderFilters($value, $var['filters']);

        return $value;
    }

    /**/
    private function _renderFilters($value, $filters)
    {
        if (is_array($filters))
        {
            foreach ($filters as $filter)
            {
                // operate accrding filter's identifier
                switch ($filter[0])
                {
                    // fetching subvalue from value,
                    // that could be an array or an object
                    case 'fetch':
                        // retrieve fetchname
                        $param = ($filter[1] !== null)? $filter[1][0] : null;
                        $fetchname = $this->_renderVariable($param);
                        if (! ($fetchname = \emperor\ensure_string($fetchname)) ) { $value = null; break;}

                        // fetch value (from an array or an object)
                        if (is_array($value) && isset($value[$fetchname])) $value = $value[$fetchname];
                        elseif (is_object($value)) $value = $value->$fetchname;
                        else $value = null;
                        break;

                    // retrieve current iteration
                    case 'iteration':
                        $value = ($this->_last_iteration !== null)? $this->_last_iteration : null;
                        break;
                    case 'key':
                        $value = ($this->_last_keyname !== null)? $this->_last_keyname : null;
                        break;

                    case 'odd':
                        $value = $value % 2 != 0;
                        break;

                    case 'even':
                        $value = $value % 2 == 0;
                        break;

                    case 'not':
                        $value = !$value;
                        break;



                    // date filter
                    // formats given date to a format "DD.MM.YYYY"
                    // accepts both timestamps and DateTime instances
                    case 'date':
                        $format = isset($filter[1][0])? (string) $filter[1][0]['content'] : "Y-m-d";
                        if ($value) $value = date($format, $value);
                        break;

                    // russian date filter
                    // formats given date to a format "DD.MM.YYYY"
                    // accepts both timestamps and DateTime instances
                    case 'date_ru':
                        break;

                /// Strings filters

                    // makes a value's every word capitalized
                    case 'title':
                        $value = ucwords(\emperor\ensure_string($value));
                        break;

                    // makes a value's first character uppercase
                    case 'capitalize':
                        $value = ucfirst(\emperor\ensure_string($value));
                        break;

                    // makes a value uppercase
                    case 'upper':
                        $value = strtoupper(\emperor\ensure_string($value));
                        break;

                    // makes a value lowercase
                    case 'lower':
                        $value = strtolower(\emperor\ensure_string($value));
                        break;

                /// Safety filters

                    // strips HTML/PHP tags from a value
                    case 'striptags':
                        $value = strip_tags(\emperor\ensure_string($value));
                        break;

                    // convert special characters to HTML entities
                    case 'escape_html':
                        $value = htmlspecialchars(\emperor\ensure_string($value));
                        break;

                /// Functional filters

                    // counts length of string, array or object,
                    // returnes empty string for numbers
                    case 'length':
                        if (is_string($value)) $value = strlen($value);
                        if (is_array($value) || is_object($value)) $value = count($value);
                        break;

                    case 'with':
                        if ($value instanceof Inset) {
                            $var_name = isset($filter[1][0])? (string) $filter[1][0]['content'] : null;
                            $var_value = isset($filter[1][1])? $this->_renderVariable($filter[1][1]) : null;
                            $value->setVar($var_name, $var_value);
                        }
                        break;

                /// Unknown filter, do nothing
                    default:
                        break;
                }
            }
        }
        return $value;
    }


    // Variables

    /**
     * Sets several inset's variables values, given in associative array.
     * Returnes TRUE if all variables were set successully, FALSE otherwise.
     * @param array $vars
     * @return bool
     */
    public function setVars(array $vars)
    {
        $allok = true;
        foreach ($vars as $name => $value) $allok &= $this->setVar($name, $value);
        return $allok;
    }

    /**
     * Sets a specified inset's variable value.
     * Returnes TRUE on success, FALSE otherwise.
     * @param string $name
     * @param mixed $value
     * @return bool
     */
    public function setVar($name, $value)
    {
        if (is_string($name) && $name !== '') {
            $this->vars[$name] = $value;
            return true;
        } else {
            return false;
        }
    }

    /**
     * Checks whether specified inset's variable was defined.
     * @param string $name
     * @return bool
     */
    public function hasVar($name)
    {
        $name = (string) $name;
        return isset($this->vars[$name]);
    }

    /**
     * Returnes a specified inset's variable value, or NULL if it isn't found.
     * @param string $name
     * @return mixed|null
     */
    public function getVar($name)
    {
        $name = (string) $name;
        return isset($this->vars[$name])? $this->vars[$name] : null;
    }

    /**
     * Returnes an assocative array, representing all inset's variables been set.
     * @return array
     */
    public function getVars()
    {
        return $this->vars;
    }

    /**
     * Clears specified inset's variable.
     * @param string $name
     */
    public function clearVar($name)
    {
        $name = (string) $name;
        if (isset($this->vars[$name])) unset($this->vars[$name]);
    }

    /**
     * Clears all inset's variables.
     */
    public function clearVars()
    {
        $this->vars = array();
    }


    // Metas

    /**
     * Checks whether any meta was retrieved in last render.
     * @return bool
     */
    public function hasMeta()
    {
        return $this->meta !== null;
    }

    /**
     * Returnes meta retrieved in last rendering, or NULL if there was no meta.
     * @return \emperor\eddy\Entry|null
     */
    public function getMeta()
    {
        return $this->meta;
    }


    // Fillers anf slots

    /**
     * Sets several inset's fillers, given in associative array.
     * Returnes TRUE if all fillers were set successully, FALSE otherwise.
     * @param array $fillers
     * @return bool
     */
    public function setFillers(array $fillers)
    {
        $allok = true;
        foreach ($fillers as $name => $value) $allok &= $this->setFiller($name, $value);
        return $allok;
    }

    /**
     * Sets an inset's filler to a specified slot.
     * Filler could be an another Inset, or string, or NULL.
     * Returnes TRUE on success, FALSE otherwise.
     * @param string $slot
     * @param Inset|string|null $filler
     * @return bool
     */
    public function setFiller($slot, $filler)
    {
        if ( $slot && (is_string($slot) || is_int($slot)) and
             ($filler instanceof Inset || is_string($filler) || is_array($filler) || $filler === null ) )
        {
            $this->fillers[$slot] = $filler;
            return true;
        } else {
            return false;
        }
    }

    /**
     * Append more filler to a specified slot.
     * Filler could be an another Inset, or string, or NULL.
     * Returnes TRUE on success, FALSE otherwise.
     * @param string $slot
     * @param Inset|string|null $filler
     * @return bool
     */
    public function appendFiller($slot, $filler)
    {
        if ( $slot && (is_string($slot) || is_int($slot)) and
             ($filler instanceof Inset || is_string($filler) || is_array($filler) || $filler === null ) )
        {
            // slot is already filled with fillers?
            if ($this->hasFiller($slot)) {
                $morefillers = is_array($this->fillers[$slot])? $this->fillers[$slot] : array($this->fillers[$slot]);
                if (is_array($filler)) $morefillers += $filler; else $morefillers[] = $filler;
                $this->fillers[$slot] = $morefillers;
            }
            // or fill as new!
            else {
                $this->fillers[$slot] = $filler;
            }
            return true;
        } else {
            return false;
        }

    }

    /**
     * Checks whether an inset's filler for specified slot was defined.
     * @param string $slot
     * @return bool
     */
    public function hasFiller($slot)
    {
        $slot = is_int($slot)? $slot : (string) $slot;
        return array_key_exists($slot, $this->fillers);
    }

    /**
     * Returnes an inset's filler for specified slot, or NULL if it isn't found.
     * @param string $slot
     * @return Inset|string|null
     */
    public function getFiller($slot)
    {
        $slot = is_int($slot)? $slot : (string) $slot;
        return $this->hasFiller($slot)? $this->fillers[$slot] : null;
    }

    /**
     * Returnes an assocative array, representing all inset's fillers been defined.
     * @return array
     */
    public function getFillers()
    {
        return $this->fillers;
    }

    /**
     * Clears specified inset's filler.
     * @param string $slot
     */
    public function clearFiller($slot)
    {
        $slot = is_int($slot)? $slot : (string) $slot;
        unset($this->fillers[$slot]);
    }

    /**
     * Clears all inset's fillers.
     */
    public function clearFillers()
    {
        $this->fillers = array();
    }

    /**
     * Checks whether specified slot was retrieved in last render.
     * @var string $slot
     * @return bool
     */
    public function hasSlot($slot)
    {
        return in_array($slot, $this->slots);
    }

    /**
     * Checks whether any slots were retrieved in last render.
     * @return bool
     */
    public function hasSlots()
    {
        return count($this->slots) > 0;
    }

    /**
     * Returnes slots retrieved in last rendering, or empty array if no slots
     * nas been retrieved.
     * @return array
     */
    public function getSlots()
    {
        return $this->slots;
    }

    public function getRenderedInsets()
    {
        return $this->rendered_insets;
    }

}
 