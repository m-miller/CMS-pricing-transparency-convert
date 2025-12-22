<?php
/**
 * Convert CSV pricing transparency file to CMS In-Network Rates JSON format
 */

function parseNumber($value) {
    if (empty($value)) {
        return null;
    }
    
    $trimmed = trim($value);
    $invalid_values = ['Not on Fee Schedule', 'No Fee Schedule', 'Not Applicable', ''];
    
    if (in_array($trimmed, $invalid_values)) {
        return null;
    }
    
    try {
        return floatval(str_replace(',', '', $trimmed));
    } catch (Exception $e) {
        return null;
    }
}

function convertCsvToInNetworkRates($csvFilePath, $outputJsonPath) {
    $fileContent = file_get_contents($csvFilePath);
    if ($fileContent === false) {
        throw new Exception("Failed to read CSV file: $csvFilePath");
    }
    
    // Convert encoding if needed
    $fileContent = mb_convert_encoding($fileContent, 'UTF-8', 'ISO-8859-1');
    
    $rows = array_map('str_getcsv', explode("\n", $fileContent));
    
    $hospitalName = $rows[1][0];
    $lastUpdated = $rows[1][1];
    $hospitalAddress = $rows[1][2];
    $licenseNumber = $rows[1][3];
    
    // Convert date format to YYYY-MM-DD
    $dateParts = explode('_', $lastUpdated);
    $lastUpdatedIso = sprintf(
        '%s-%s-%s',
        $dateParts[2],
        str_pad($dateParts[0], 2, '0', STR_PAD_LEFT),
        str_pad($dateParts[1], 2, '0', STR_PAD_LEFT)
    );
    
    $lastUpdatedDate = new DateTime($lastUpdatedIso);
    $expirationDate = $lastUpdatedDate->modify('+1 year')->format('Y-m-d');
    
    $headers = $rows[3];
    
    echo "Hospital: $hospitalName\n";
    echo "Last Updated: $lastUpdatedIso\n";
    echo "Expiration Date: $expirationDate\n";
    echo "Converting to Transparency in Coverage In-Network Rates format...\n";
    
    $jsonOutput = [
        'reporting_entity_name' => $hospitalName,
        'reporting_entity_type' => 'hospital',
        'last_updated_on' => $lastUpdatedIso,
        'version' => 'v1.0.0',
        'in_network' => []
    ];
    
    for ($i = 4; $i < count($rows); $i++) {
        $row = $rows[$i];
        
        if (count($row) < 10 || empty($row[0]) || $row[0] === 'CPT_Code') {
            continue;
        }
        
        $cptCode = trim($row[0]);
        $revCode = trim($row[1]);
        $description = trim($row[2]);
        $setting = trim($row[3]);
        
        if (empty($cptCode)) {
            continue;
        }
        
        // Determine billing code type
        $billingCodeType = preg_match('/^[HST]/', $cptCode) ? 'HCPCS' : 'CPT';
        
        $negotiatedRatesMap = [];
        
        // Process each payer column from index 9
        for ($j = 9; $j < min(count($row), count($headers)); $j++) {
            if ($j >= count($headers)) {
                break;
            }
            
            $payerHeader = trim($headers[$j]);
            $chargeValue = $j < count($row) ? parseNumber($row[$j]) : null;
            
            if ($chargeValue !== null && $chargeValue > 0) {
                $payerName = strpos($payerHeader, '_') !== false ? 
                    explode('_', $payerHeader)[0] : $payerHeader;
                $planName = $payerHeader;
                
                if (!isset($negotiatedRatesMap[$payerName])) {
                    $negotiatedRatesMap[$payerName] = [
                        'plans' => [],
                        'rates' => []
                    ];
                }
                
                $negotiatedRatesMap[$payerName]['plans'][] = $planName;
                $negotiatedRatesMap[$payerName]['rates'][] = [
                    'negotiated_type' => 'fee schedule',
                    'negotiated_rate' => $chargeValue,
                    'expiration_date' => $expirationDate,
                    'billing_class' => $setting === 'Inpatient' ? 'institutional' : 'professional'
                ];
            }
        }
        
        $negotiatedRates = [];
        foreach ($negotiatedRatesMap as $payerName => $payerData) {
            // Create a single negotiated rate entry per payer/plan
            for ($idx = 0; $idx < count($payerData['plans']); $idx++) {
                $negotiatedRates[] = [
                    'provider_groups' => [[
                        'npi' => [],
                        'tin' => [
                            'type' => 'ein',
                            'value' => $licenseNumber
                        ]
                    ]],
                    'negotiated_prices' => [$payerData['rates'][$idx]]
                ];
            }
        }
        
        $inNetworkEntry = [
            'negotiation_arrangement' => 'ffs',
            'name' => $description,
            'billing_code_type' => $billingCodeType,
            'billing_code_type_version' => '2024',
            'billing_code' => $cptCode,
            'description' => $description,
            'negotiated_rates' => $negotiatedRates
        ];
        
        if (!empty($revCode) && $revCode !== 'Not Applicable') {
            $inNetworkEntry['billing_code_modifier'] = [$revCode];
        }
        
        $jsonOutput['in_network'][] = $inNetworkEntry;
    }
    
    $totalRates = array_reduce(
        $jsonOutput['in_network'], 
        function($sum, $e) { return $sum + count($e['negotiated_rates']); }, 
        0
    );
    
    echo "Processed " . count($jsonOutput['in_network']) . " in-network entries\n";
    echo "Total negotiated rates: $totalRates\n";
    
    // Write to JSON file
    $jsonString = json_encode($jsonOutput, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (file_put_contents($outputJsonPath, $jsonString) === false) {
        throw new Exception("Failed to write JSON file: $outputJsonPath");
    }
    
    echo "\nJSON file created: $outputJsonPath\n";
    
    return $jsonOutput;
}

// Usage

/*
if (php_sapi_name() === 'cli') {
    $inputCsvPath = $argv[1] ?? ''; // title of csv file
    $outputJsonPath = $argv[2] ?? '.json'; // title of output json in format YYYY-MM-DD_cms_medicare_in-network-rates.json
    
    try {
        convertCsvToInNetworkRates($inputCsvPath, $outputJsonPath);
    } catch (Exception $e) {
        echo "Error during conversion: " . $e->getMessage() . "\n";
        exit(1);
    }
}
    */
?>
