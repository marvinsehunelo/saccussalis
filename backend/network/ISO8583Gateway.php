<?php
/**
 * ISO8583 Gateway Class
 * Handles ISO 8583 financial transaction message formatting and parsing
 * Supports version 1987 and 1993 specifications
 */

class ISO8583Gateway {
    
    // Message Type Indicators (MTI)
    const MTI_AUTHORIZATION_REQUEST = '0100';
    const MTI_AUTHORIZATION_RESPONSE = '0110';
    const MTI_FINANCIAL_REQUEST = '0200';
    const MTI_FINANCIAL_RESPONSE = '0210';
    const MTI_REVERSAL_REQUEST = '0400';
    const MTI_REVERSAL_RESPONSE = '0410';
    const MTI_NETWORK_MANAGEMENT_REQUEST = '0800';
    const MTI_NETWORK_MANAGEMENT_RESPONSE = '0810';
    
    // Field definitions
    private $fieldDefinitions = [
        0 => ['name' => 'Message Type Indicator', 'type' => 'n', 'length' => 4, 'variable' => false],
        1 => ['name' => 'Bit Map', 'type' => 'b', 'length' => 16, 'variable' => false],
        2 => ['name' => 'Primary Account Number (PAN)', 'type' => 'n', 'length' => 19, 'variable' => true],
        3 => ['name' => 'Processing Code', 'type' => 'n', 'length' => 6, 'variable' => false],
        4 => ['name' => 'Transaction Amount', 'type' => 'n', 'length' => 12, 'variable' => false],
        5 => ['name' => 'Settlement Amount', 'type' => 'n', 'length' => 12, 'variable' => false],
        6 => ['name' => 'Cardholder Billing Amount', 'type' => 'n', 'length' => 12, 'variable' => false],
        7 => ['name' => 'Transmission Date & Time', 'type' => 'n', 'length' => 10, 'variable' => false],
        8 => ['name' => 'Cardholder Billing Fee', 'type' => 'n', 'length' => 8, 'variable' => false],
        9 => ['name' => 'Conversion Rate', 'type' => 'n', 'length' => 8, 'variable' => false],
        10 => ['name' => 'Conversion Rate (Cardholder)', 'type' => 'n', 'length' => 8, 'variable' => false],
        11 => ['name' => 'System Trace Audit Number', 'type' => 'n', 'length' => 6, 'variable' => false],
        12 => ['name' => 'Local Transaction Time', 'type' => 'n', 'length' => 6, 'variable' => false],
        13 => ['name' => 'Local Transaction Date', 'type' => 'n', 'length' => 4, 'variable' => false],
        14 => ['name' => 'Expiration Date', 'type' => 'n', 'length' => 4, 'variable' => false],
        15 => ['name' => 'Settlement Date', 'type' => 'n', 'length' => 4, 'variable' => false],
        16 => ['name' => 'Currency Conversion Date', 'type' => 'n', 'length' => 4, 'variable' => false],
        17 => ['name' => 'Capture Date', 'type' => 'n', 'length' => 4, 'variable' => false],
        18 => ['name' => 'Merchant Type', 'type' => 'n', 'length' => 4, 'variable' => false],
        19 => ['name' => 'Acquiring Institution Country Code', 'type' => 'n', 'length' => 3, 'variable' => false],
        20 => ['name' => 'PAN Extended', 'type' => 'n', 'length' => 3, 'variable' => false],
        21 => ['name' => 'Forwarding Institution Country Code', 'type' => 'n', 'length' => 3, 'variable' => false],
        22 => ['name' => 'Point of Service Entry Mode', 'type' => 'n', 'length' => 3, 'variable' => false],
        23 => ['name' => 'Card Sequence Number', 'type' => 'n', 'length' => 3, 'variable' => false],
        24 => ['name' => 'Network International Identifier', 'type' => 'n', 'length' => 3, 'variable' => false],
        25 => ['name' => 'Point of Service Condition Code', 'type' => 'n', 'length' => 2, 'variable' => false],
        26 => ['name' => 'Point of Service Capture Code', 'type' => 'n', 'length' => 2, 'variable' => false],
        27 => ['name' => 'Authorizing Identification Response Length', 'type' => 'n', 'length' => 1, 'variable' => false],
        28 => ['name' => 'Transaction Fee Amount', 'type' => 'n', 'length' => 9, 'variable' => false],
        29 => ['name' => 'Settlement Fee Amount', 'type' => 'n', 'length' => 9, 'variable' => false],
        30 => ['name' => 'Transaction Processing Fee Amount', 'type' => 'n', 'length' => 9, 'variable' => false],
        31 => ['name' => 'Settlement Processing Fee Amount', 'type' => 'n', 'length' => 9, 'variable' => false],
        32 => ['name' => 'Acquiring Institution ID', 'type' => 'n', 'length' => 11, 'variable' => true],
        33 => ['name' => 'Forwarding Institution ID', 'type' => 'n', 'length' => 11, 'variable' => true],
        34 => ['name' => 'Primary Account Number Extended', 'type' => 'n', 'length' => 28, 'variable' => true],
        35 => ['name' => 'Track 2 Data', 'type' => 'z', 'length' => 37, 'variable' => true],
        36 => ['name' => 'Track 3 Data', 'type' => 'z', 'length' => 104, 'variable' => true],
        37 => ['name' => 'Retrieval Reference Number', 'type' => 'an', 'length' => 12, 'variable' => false],
        38 => ['name' => 'Authorization Identification Response', 'type' => 'an', 'length' => 6, 'variable' => false],
        39 => ['name' => 'Response Code', 'type' => 'an', 'length' => 2, 'variable' => false],
        40 => ['name' => 'Service Restriction Code', 'type' => 'an', 'length' => 3, 'variable' => false],
        41 => ['name' => 'Card Acceptor Terminal ID', 'type' => 'ans', 'length' => 8, 'variable' => false],
        42 => ['name' => 'Card Acceptor ID', 'type' => 'ans', 'length' => 15, 'variable' => false],
        43 => ['name' => 'Card Acceptor Name/Location', 'type' => 'ans', 'length' => 40, 'variable' => false],
        44 => ['name' => 'Additional Response Data', 'type' => 'ans', 'length' => 25, 'variable' => true],
        45 => ['name' => 'Track 1 Data', 'type' => 'z', 'length' => 76, 'variable' => true],
        46 => ['name' => 'Additional Data - ISO', 'type' => 'ans', 'length' => 999, 'variable' => true],
        47 => ['name' => 'Additional Data - National', 'type' => 'ans', 'length' => 999, 'variable' => true],
        48 => ['name' => 'Additional Data - Private', 'type' => 'ans', 'length' => 999, 'variable' => true],
        49 => ['name' => 'Transaction Currency Code', 'type' => 'n', 'length' => 3, 'variable' => false],
        50 => ['name' => 'Settlement Currency Code', 'type' => 'n', 'length' => 3, 'variable' => false],
        51 => ['name' => 'Cardholder Billing Currency Code', 'type' => 'n', 'length' => 3, 'variable' => false],
        52 => ['name' => 'PIN Data', 'type' => 'b', 'length' => 8, 'variable' => false],
        53 => ['name' => 'Security Related Control Information', 'type' => 'n', 'length' => 16, 'variable' => false],
        54 => ['name' => 'Additional Amounts', 'type' => 'n', 'length' => 120, 'variable' => true],
        55 => ['name' => 'ICC Data', 'type' => 'b', 'length' => 255, 'variable' => true],
        56 => ['name' => 'Reserved ISO', 'type' => 'ans', 'length' => 35, 'variable' => true],
        57 => ['name' => 'Reserved National', 'type' => 'ans', 'length' => 35, 'variable' => true],
        58 => ['name' => 'Reserved Private', 'type' => 'ans', 'length' => 35, 'variable' => true],
        59 => ['name' => 'Reserved for Future Use', 'type' => 'ans', 'length' => 35, 'variable' => true],
        60 => ['name' => 'Reserved National', 'type' => 'ans', 'length' => 35, 'variable' => true],
        61 => ['name' => 'Reserved Private', 'type' => 'ans', 'length' => 35, 'variable' => true],
        62 => ['name' => 'Reserved Private', 'type' => 'ans', 'length' => 35, 'variable' => true],
        63 => ['name' => 'Reserved Private', 'type' => 'ans', 'length' => 35, 'variable' => true],
        64 => ['name' => 'Message Authentication Code (MAC)', 'type' => 'b', 'length' => 8, 'variable' => false],
        65 => ['name' => 'Bit Map Extended', 'type' => 'b', 'length' => 16, 'variable' => false],
        66 => ['name' => 'Settlement Code', 'type' => 'n', 'length' => 1, 'variable' => false],
        67 => ['name' => 'Extended Payment Code', 'type' => 'n', 'length' => 2, 'variable' => false],
        68 => ['name' => 'Receiving Institution Country Code', 'type' => 'n', 'length' => 3, 'variable' => false],
        69 => ['name' => 'Settlement Institution Country Code', 'type' => 'n', 'length' => 3, 'variable' => false],
        70 => ['name' => 'Network Management Information Code', 'type' => 'n', 'length' => 3, 'variable' => false],
        71 => ['name' => 'Message Number', 'type' => 'n', 'length' => 4, 'variable' => false],
        72 => ['name' => 'Message Number Last', 'type' => 'n', 'length' => 4, 'variable' => false],
        73 => ['name' => 'Action Date', 'type' => 'n', 'length' => 6, 'variable' => false],
        74 => ['name' => 'Number of Credits', 'type' => 'n', 'length' => 10, 'variable' => false],
        75 => ['name' => 'Credits Reversal Number', 'type' => 'n', 'length' => 10, 'variable' => false],
        76 => ['name' => 'Number of Debits', 'type' => 'n', 'length' => 10, 'variable' => false],
        77 => ['name' => 'Debits Reversal Number', 'type' => 'n', 'length' => 10, 'variable' => false],
        78 => ['name' => 'Transfer Number', 'type' => 'n', 'length' => 10, 'variable' => false],
        79 => ['name' => 'Transfer Reversal Number', 'type' => 'n', 'length' => 10, 'variable' => false],
        80 => ['name' => 'Credits Number', 'type' => 'n', 'length' => 10, 'variable' => false],
        81 => ['name' => 'Credits Reversal Number (2)', 'type' => 'n', 'length' => 10, 'variable' => false],
        82 => ['name' => 'Debits Number', 'type' => 'n', 'length' => 10, 'variable' => false],
        83 => ['name' => 'Debits Reversal Number (2)', 'type' => 'n', 'length' => 10, 'variable' => false],
        84 => ['name' => 'Transfer Number (2)', 'type' => 'n', 'length' => 10, 'variable' => false],
        85 => ['name' => 'Transfer Reversal Number (2)', 'type' => 'n', 'length' => 10, 'variable' => false],
        86 => ['name' => 'Total Credits', 'type' => 'n', 'length' => 15, 'variable' => false],
        87 => ['name' => 'Total Credits Reversal', 'type' => 'n', 'length' => 15, 'variable' => false],
        88 => ['name' => 'Total Debits', 'type' => 'n', 'length' => 15, 'variable' => false],
        89 => ['name' => 'Total Debits Reversal', 'type' => 'n', 'length' => 15, 'variable' => false],
        90 => ['name' => 'Original Data Elements', 'type' => 'n', 'length' => 42, 'variable' => false],
        91 => ['name' => 'File Update Code', 'type' => 'an', 'length' => 1, 'variable' => false],
        92 => ['name' => 'File Security Code', 'type' => 'an', 'length' => 2, 'variable' => false],
        93 => ['name' => 'Response Indicator', 'type' => 'an', 'length' => 5, 'variable' => false],
        94 => ['name' => 'Service Indicator', 'type' => 'an', 'length' => 7, 'variable' => false],
        95 => ['name' => 'Replacement Amounts', 'type' => 'ans', 'length' => 42, 'variable' => false],
        96 => ['name' => 'Message Security Code', 'type' => 'b', 'length' => 8, 'variable' => false],
        97 => ['name' => 'Net Settlement Amount', 'type' => 'n', 'length' => 16, 'variable' => false],
        98 => ['name' => 'Payee', 'type' => 'ans', 'length' => 25, 'variable' => false],
        99 => ['name' => 'Settlement Institution ID', 'type' => 'n', 'length' => 11, 'variable' => true],
        100 => ['name' => 'Receiving Institution ID', 'type' => 'n', 'length' => 11, 'variable' => true],
        101 => ['name' => 'File Name', 'type' => 'ans', 'length' => 17, 'variable' => true],
        102 => ['name' => 'Account Identification 1', 'type' => 'ans', 'length' => 28, 'variable' => true],
        103 => ['name' => 'Account Identification 2', 'type' => 'ans', 'length' => 28, 'variable' => true],
        104 => ['name' => 'Transaction Description', 'type' => 'ans', 'length' => 100, 'variable' => true],
        105 => ['name' => 'Reserved for ISO Use', 'type' => 'ans', 'length' => 999, 'variable' => true],
        106 => ['name' => 'Reserved for ISO Use', 'type' => 'ans', 'length' => 999, 'variable' => true],
        107 => ['name' => 'Reserved for ISO Use', 'type' => 'ans', 'length' => 999, 'variable' => true],
        108 => ['name' => 'Reserved for ISO Use', 'type' => 'ans', 'length' => 999, 'variable' => true],
        109 => ['name' => 'Reserved for ISO Use', 'type' => 'ans', 'length' => 999, 'variable' => true],
        110 => ['name' => 'Reserved for ISO Use', 'type' => 'ans', 'length' => 999, 'variable' => true],
        111 => ['name' => 'Reserved for ISO Use', 'type' => 'ans', 'length' => 999, 'variable' => true],
        112 => ['name' => 'Reserved for National Use', 'type' => 'ans', 'length' => 999, 'variable' => true],
        113 => ['name' => 'Reserved for National Use', 'type' => 'ans', 'length' => 999, 'variable' => true],
        114 => ['name' => 'Reserved for National Use', 'type' => 'ans', 'length' => 999, 'variable' => true],
        115 => ['name' => 'Reserved for National Use', 'type' => 'ans', 'length' => 999, 'variable' => true],
        116 => ['name' => 'Reserved for National Use', 'type' => 'ans', 'length' => 999, 'variable' => true],
        117 => ['name' => 'Reserved for National Use', 'type' => 'ans', 'length' => 999, 'variable' => true],
        118 => ['name' => 'Reserved for National Use', 'type' => 'ans', 'length' => 999, 'variable' => true],
        119 => ['name' => 'Reserved for National Use', 'type' => 'ans', 'length' => 999, 'variable' => true],
        120 => ['name' => 'Reserved for Private Use', 'type' => 'ans', 'length' => 999, 'variable' => true],
        121 => ['name' => 'Reserved for Private Use', 'type' => 'ans', 'length' => 999, 'variable' => true],
        122 => ['name' => 'Reserved for Private Use', 'type' => 'ans', 'length' => 999, 'variable' => true],
        123 => ['name' => 'Reserved for Private Use', 'type' => 'ans', 'length' => 999, 'variable' => true],
        124 => ['name' => 'Reserved for Private Use', 'type' => 'ans', 'length' => 999, 'variable' => true],
        125 => ['name' => 'Reserved for Private Use', 'type' => 'ans', 'length' => 999, 'variable' => true],
        126 => ['name' => 'Reserved for Private Use', 'type' => 'ans', 'length' => 999, 'variable' => true],
        127 => ['name' => 'Reserved for Private Use', 'type' => 'ans', 'length' => 999, 'variable' => true],
        128 => ['name' => 'Message Authentication Code (MAC) - 2', 'type' => 'b', 'length' => 16, 'variable' => false]
    ];
    
    private $message = [];
    private $bitmap = [];
    private $fields = [];
    private $mti = '';
    private $binaryMode = false;
    
    /**
     * Constructor
     * 
     * @param bool $binaryMode Whether to use binary encoding for bitmap
     */
    public function __construct($binaryMode = false) {
        $this->binaryMode = $binaryMode;
        $this->reset();
    }
    
    /**
     * Reset the message
     */
    public function reset() {
        $this->message = [];
        $this->bitmap = [];
        $this->fields = [];
        $this->mti = '';
    }
    
    /**
     * Set Message Type Indicator
     * 
     * @param string $mti MTI code (e.g., '0100', '0110')
     * @return $this
     */
    public function setMTI($mti) {
        if (strlen($mti) !== 4 || !is_numeric($mti)) {
            throw new InvalidArgumentException('MTI must be 4 numeric characters');
        }
        $this->mti = $mti;
        return $this;
    }
    
    /**
     * Get MTI
     * 
     * @return string
     */
    public function getMTI() {
        return $this->mti;
    }
    
    /**
     * Set a field value
     * 
     * @param int $fieldNumber Field number (2-128)
     * @param string $value Field value
     * @return $this
     */
    public function setField($fieldNumber, $value) {
        if ($fieldNumber < 2 || $fieldNumber > 128) {
            throw new InvalidArgumentException('Field number must be between 2 and 128');
        }
        
        $fieldDef = $this->fieldDefinitions[$fieldNumber];
        
        // Validate field length
        if ($fieldDef['variable']) {
            $maxLength = $fieldDef['length'];
            if (strlen($value) > $maxLength) {
                throw new InvalidArgumentException(
                    "Field $fieldNumber value exceeds maximum length of $maxLength"
                );
            }
        } else {
            $expectedLength = $fieldDef['length'];
            if (strlen($value) !== $expectedLength) {
                throw new InvalidArgumentException(
                    "Field $fieldNumber must be exactly $expectedLength characters"
                );
            }
        }
        
        $this->fields[$fieldNumber] = $value;
        return $this;
    }
    
    /**
     * Get a field value
     * 
     * @param int $fieldNumber Field number
     * @return string|null
     */
    public function getField($fieldNumber) {
        return isset($this->fields[$fieldNumber]) ? $this->fields[$fieldNumber] : null;
    }
    
    /**
     * Build the bitmap based on set fields
     */
    private function buildBitmap() {
        $this->bitmap = array_fill(0, 128, 0);
        
        // Field 1 is always present (bitmap)
        $this->bitmap[0] = 1;
        
        foreach (array_keys($this->fields) as $fieldNumber) {
            if ($fieldNumber >= 1 && $fieldNumber <= 128) {
                $this->bitmap[$fieldNumber - 1] = 1;
            }
        }
        
        // Check if we need extended bitmap (field 65 present)
        if (isset($this->fields[65])) {
            // Field 65 indicates extended bitmap
            $this->bitmap[64] = 1;
        }
    }
    
    /**
     * Convert bitmap to hex string
     * 
     * @return string
     */
    private function bitmapToHex() {
        $this->buildBitmap();
        
        // Determine if we need primary bitmap only or extended
        $bitmapBits = $this->bitmap;
        
        // If field 65 is not present and no fields beyond 64, use primary only
        $maxField = 64;
        foreach ($this->fields as $fieldNumber => $value) {
            if ($fieldNumber > 64) {
                $maxField = 128;
                break;
            }
        }
        
        if ($maxField > 64) {
            $this->bitmap[64] = 1; // Set extended bitmap indicator
        }
        
        $bytes = [];
        for ($i = 0; $i < 16; $i++) {
            $byteValue = 0;
            for ($j = 0; $j < 8; $j++) {
                $index = ($i * 8) + $j;
                if (isset($this->bitmap[$index]) && $this->bitmap[$index] == 1) {
                    $byteValue |= (1 << (7 - $j));
                }
            }
            $bytes[] = chr($byteValue);
        }
        
        if ($this->binaryMode) {
            return implode('', $bytes);
        } else {
            return bin2hex(implode('', $bytes));
        }
    }
    
    /**
     * Parse bitmap from hex or binary string
     * 
     * @param string $bitmapString Hex or binary string
     * @param bool $isBinary Whether input is binary
     * @return array
     */
    private function parseBitmap($bitmapString, $isBinary = false) {
        if ($isBinary) {
            $bytes = str_split($bitmapString);
            foreach ($bytes as $i => $byte) {
                $byteValue = ord($byte);
                for ($j = 0; $j < 8; $j++) {
                    $bitIndex = ($i * 8) + $j;
                    $this->bitmap[$bitIndex] = ($byteValue >> (7 - $j)) & 1;
                }
            }
        } else {
            $hexString = $bitmapString;
            if (strlen($hexString) == 32) {
                // Primary bitmap only
                for ($i = 0; $i < 16; $i++) {
                    $hexByte = substr($hexString, $i * 2, 2);
                    $byteValue = hexdec($hexByte);
                    for ($j = 0; $j < 8; $j++) {
                        $bitIndex = ($i * 8) + $j;
                        $this->bitmap[$bitIndex] = ($byteValue >> (7 - $j)) & 1;
                    }
                }
            } elseif (strlen($hexString) == 64) {
                // Extended bitmap
                for ($i = 0; $i < 32; $i++) {
                    $hexByte = substr($hexString, $i * 2, 2);
                    $byteValue = hexdec($hexByte);
                    for ($j = 0; $j < 8; $j++) {
                        $bitIndex = ($i * 8) + $j;
                        $this->bitmap[$bitIndex] = ($byteValue >> (7 - $j)) & 1;
                    }
                }
            }
        }
        
        return $this->bitmap;
    }
    
    /**
     * Build the complete ISO 8583 message
     * 
     * @return string
     */
    public function build() {
        if (empty($this->mti)) {
            throw new RuntimeException('MTI must be set before building message');
        }
        
        $this->buildBitmap();
        
        // Start with MTI
        $message = $this->mti;
        
        // Add bitmap
        $message .= $this->bitmapToHex();
        
        // Add fields in order
        $fieldNumbers = array_keys($this->fields);
        sort($fieldNumbers);
        
        foreach ($fieldNumbers as $fieldNumber) {
            $value = $this->fields[$fieldNumber];
            $fieldDef = $this->fieldDefinitions[$fieldNumber];
            
            // For variable length fields, add length prefix
            if ($fieldDef['variable']) {
                $length = strlen($value);
                $lengthPrefix = str_pad($length, 2, '0', STR_PAD_LEFT);
                $message .= $lengthPrefix . $value;
            } else {
                $message .= $value;
            }
        }
        
        $this->message = $message;
        return $message;
    }
    
    /**
     * Parse an ISO 8583 message
     * 
     * @param string $message Raw ISO 8583 message
     * @param bool $isBinary Whether the bitmap is binary
     * @return array Parsed fields
     */
    public function parse($message, $isBinary = false) {
        $this->reset();
        
        $position = 0;
        
        // Extract MTI (4 characters)
        $this->mti = substr($message, $position, 4);
        $position += 4;
        
        // Extract bitmap
        if ($isBinary) {
            // Primary bitmap is 8 bytes (16 hex characters)
            $bitmapBinary = substr($message, $position, 8);
            $position += 8;
            $this->parseBitmap($bitmapBinary, true);
            
            // Check if extended bitmap is needed
            if (isset($this->bitmap[64]) && $this->bitmap[64] == 1) {
                $extendedBitmap = substr($message, $position, 8);
                $position += 8;
                $this->parseBitmap($extendedBitmap, true);
            }
        } else {
            // Primary bitmap is 16 hex characters (8 bytes)
            $bitmapHex = substr($message, $position, 16);
            $position += 16;
            $this->parseBitmap($bitmapHex, false);
            
            // Check if extended bitmap is needed
            if (isset($this->bitmap[64]) && $this->bitmap[64] == 1) {
                $extendedBitmap = substr($message, $position, 16);
                $position += 16;
                $this->parseBitmap($extendedBitmap, false);
            }
        }
        
        // Parse fields based on bitmap
        for ($fieldNumber = 1; $fieldNumber <= 128; $fieldNumber++) {
            // Skip field 1 (bitmap) and field 65 (extended bitmap indicator)
            if ($fieldNumber == 1 || $fieldNumber == 65) {
                continue;
            }
            
            if (isset($this->bitmap[$fieldNumber - 1]) && $this->bitmap[$fieldNumber - 1] == 1) {
                $fieldDef = $this->fieldDefinitions[$fieldNumber];
                
                if ($fieldDef['variable']) {
                    // Variable length field - read 2 digit length
                    $lengthPrefix = substr($message, $position, 2);
                    $position += 2;
                    $length = intval($lengthPrefix);
                    $value = substr($message, $position, $length);
                    $position += $length;
                } else {
                    // Fixed length field
                    $length = $fieldDef['length'];
                    $value = substr($message, $position, $length);
                    $position += $length;
                }
                
                $this->fields[$fieldNumber] = $value;
            }
        }
        
        return $this->fields;
    }
    
    /**
     * Get all parsed fields
     * 
     * @return array
     */
    public function getFields() {
        return $this->fields;
    }
    
    /**
     * Get raw message
     * 
     * @return string
     */
    public function getMessage() {
        return $this->message;
    }
    
    /**
     * Get bitmap as array
     * 
     * @return array
     */
    public function getBitmap() {
        return $this->bitmap;
    }
    
    /**
     * Create an authorization request
     * 
     * @param string $pan Primary Account Number
     * @param string $amount Transaction amount
     * @param string $traceNumber System trace audit number
     * @param string $terminalId Terminal ID
     * @param string $merchantId Merchant ID
     * @param string $currencyCode Currency code (e.g., '840' for USD)
     * @return string ISO 8583 message
     */
    public function createAuthorizationRequest($pan, $amount, $traceNumber, $terminalId, $merchantId, $currencyCode = '840') {
        $this->reset();
        
        $this->setMTI(self::MTI_AUTHORIZATION_REQUEST);
        $this->setField(2, str_pad($pan, 19, ' ', STR_PAD_RIGHT));
        $this->setField(3, '000000'); // Processing code
        $this->setField(4, str_pad($amount, 12, '0', STR_PAD_LEFT));
        $this->setField(7, date('mdHis')); // Transmission date/time
        $this->setField(11, str_pad($traceNumber, 6, '0', STR_PAD_LEFT));
        $this->setField(12, date('His')); // Local time
        $this->setField(13, date('md')); // Local date
        $this->setField(22, '051'); // POS entry mode
        $this->setField(25, '00'); // POS condition code
        $this->setField(41, str_pad($terminalId, 8, ' ', STR_PAD_RIGHT));
        $this->setField(42, str_pad($merchantId, 15, ' ', STR_PAD_RIGHT));
        $this->setField(49, $currencyCode);
        
        return $this->build();
    }
    
    /**
     * Create an authorization response
     * 
     * @param string $responseCode Response code (00 = Approved)
     * @param string $authCode Authorization code
     * @param string $retrievalRef Retrieval reference number
     * @param array $originalFields Original request fields
     * @return string ISO 8583 message
     */
    public function createAuthorizationResponse($responseCode, $authCode, $retrievalRef, $originalFields) {
        $this->reset();
        
        $this->setMTI(self::MTI_AUTHORIZATION_RESPONSE);
        
        // Copy relevant fields from request
        if (isset($originalFields[2])) $this->setField(2, $originalFields[2]);
        if (isset($originalFields[3])) $this->setField(3, $originalFields[3]);
        if (isset($originalFields[4])) $this->setField(4, $originalFields[4]);
        if (isset($originalFields[7])) $this->setField(7, $originalFields[7]);
        if (isset($originalFields[11])) $this->setField(11, $originalFields[11]);
        if (isset($originalFields[22])) $this->setField(22, $originalFields[22]);
        if (isset($originalFields[25])) $this->setField(25, $originalFields[25]);
        if (isset($originalFields[41])) $this->setField(41, $originalFields[41]);
        if (isset($originalFields[42])) $this->setField(42, $originalFields[42]);
        if (isset($originalFields[49])) $this->setField(49, $originalFields[49]);
        
        // Add response fields
        $this->setField(37, str_pad($retrievalRef, 12, '0', STR_PAD_LEFT));
        $this->setField(38, str_pad($authCode, 6, '0', STR_PAD_LEFT));
        $this->setField(39, $responseCode);
        
        return $this->build();
    }
    
    /**
     * Validate response code
     * 
     * @param string $responseCode
     * @return bool
     */
    public function isApproved($responseCode) {
        return $responseCode === '00';
    }
    
    /**
     * Get response code description
     * 
     * @param string $responseCode
     * @return string
     */
    public function getResponseCodeDescription($responseCode) {
        $codes = [
            '00' => 'Approved or completed successfully',
            '01' => 'Refer to card issuer',
            '02' => 'Refer to card issuer, special condition',
            '03' => 'Invalid merchant or service provider',
            '04' => 'Pick up card (no fraud)',
            '05' => 'Do not honor',
            '06' => 'Error',
            '07' => 'Pick up card, special condition (fraud account)',
            '08' => 'Honor with identification',
            '09' => 'Request in progress',
            '10' => 'Approved, partial',
            '11' => 'Approved, VIP',
            '12' => 'Invalid transaction',
            '13' => 'Invalid amount or currency mismatch',
            '14' => 'Invalid card number (no such number)',
            '15' => 'No such issuer',
            '16' => 'Approved, update track 3',
            '17' => 'Customer cancellation',
            '18' => 'Customer dispute',
            '19' => 'Re-enter transaction',
            '20' => 'Invalid response',
            '21' => 'No action taken (unable to reverse)',
            '22' => 'Suspected malfunction (switch)',
            '23' => 'Unacceptable transaction fee',
            '24' => 'File update not supported',
            '25' => 'Unable to locate record',
            '26' => 'Duplicate file update',
            '27' => 'File update edit error',
            '28' => 'File update file locked',
            '29' => 'File update not successful',
            '30' => 'Format error',
            '31' => 'Bank not supported by switch',
            '32' => 'Completed partially',
            '33' => 'Expired card, pick up',
            '34' => 'Suspected fraud, pick up',
            '35' => 'Contact acquirer, pick up',
            '36' => 'Restricted card, pick up',
            '37' => 'Call acquirer security, pick up',
            '38' => 'PIN tries exceeded, pick up',
            '39' => 'No credit account',
            '40' => 'Function not supported',
            '41' => 'Lost card, pick up',
            '42' => 'No universal account',
            '43' => 'Stolen card, pick up',
            '44' => 'No investment account',
            '45' => 'Account closed',
            '46' => 'PIN required',
            '47' => 'No checking account',
            '48' => 'No savings account',
            '49' => 'No money market account',
            '50' => 'No credit card account',
            '51' => 'Insufficient funds',
            '52' => 'No checking account',
            '53' => 'No savings account',
            '54' => 'Expired card',
            '55' => 'Incorrect PIN',
            '56' => 'No card record',
            '57' => 'Transaction not permitted to cardholder',
            '58' => 'Transaction not permitted to terminal',
            '59' => 'Suspected fraud',
            '60' => 'Contact acquirer',
            '61' => 'Exceeds withdrawal limit',
            '62' => 'Restricted card',
            '63' => 'Security violation',
            '64' => 'Original amount incorrect',
            '65' => 'Exceeds withdrawal frequency',
            '66' => 'Call acquirer security',
            '67' => 'Hard capture (requires manual intervention)',
            '68' => 'Response received too late',
            '69' => 'Advice received too late',
            '70' => 'PIN data required',
            '71' => 'PIN data required but not present',
            '72' => 'Invalid PIN block',
            '73' => 'Invalid MAC',
            '74' => 'Invalid encrypted data',
            '75' => 'PIN tries exceeded',
            '76' => 'Invalid CVV',
            '77' => 'Intervene, bank approval required',
            '78' => 'Intervene, bank approval required for partial amount',
            '79' => 'Invalid service code',
            '80' => 'Invalid date',
            '81' => 'PIN validation error',
            '82' => 'PIN validation error (CVV)',
            '83' => 'Invalid transaction data',
            '84' => 'Invalid authorization life cycle',
            '85' => 'PIN validation error (PIN key)',
            '86' => 'Invalid PIN key',
            '87' => 'PIN validation error (PIN offset)',
            '88' => 'Invalid PIN offset',
            '89' => 'Invalid MAC key',
            '90' => 'Cutoff in progress',
            '91' => 'Issuer unavailable',
            '92' => 'Unable to route',
            '93' => 'Violation of law',
            '94' => 'Duplicate transaction',
            '95' => 'Reconcile error',
            '96' => 'System malfunction',
            '97' => 'Invalid transaction',
            '98' => 'Exceeds cash limit',
            '99' => 'Host not available'
        ];
        
        return isset($codes[$responseCode]) ? $codes[$responseCode] : 'Unknown response code';
    }
}
