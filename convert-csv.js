/**
 * Convert CSV pricing transparency file to CMS In-Network Rates JSON format
 * Node.js version - requires csv-parse package
 * Install: npm install csv-parse
 * Martin Miller 12-17-2025
 */

const fs = require('fs');
const { parse } = require('csv-parse/sync');

// function to parse numeric values
function parseNumber(value) {
    if (!value || ['Not on Fee Schedule', 'No Fee Schedule', 'Not Applicable', ''].includes(value.trim())) {
        return null;
    }
    try {
        return parseFloat(value.trim().replace(',', ''));
    } catch (error) {
        return null;
    }
}

// function to add days to a date
function addDays(date, days) {
    const result = new Date(date);
    result.setDate(result.getDate() + days);
    return result;
}

// Main conversion function
function convertCsvToInNetworkRates(csvFilePath, outputJsonPath) {
    const fileContent = fs.readFileSync(csvFilePath, 'latin1');
    const rows = parse(fileContent, {
        relax_column_count: true,
        skip_empty_lines: false
    });

    // Parse hospital information
    const hospitalName = rows[1][0];
    const lastUpdated = rows[1][1];
    const hospitalAddress = rows[1][2];
    const licenseNumber = rows[1][3];

    // Convert date format from MM_DD_YYYY to YYYY-MM-DD
    const dateParts = lastUpdated.split('_');
    const lastUpdatedIso = `${dateParts[2]}-${dateParts[0].padStart(2, '0')}-${dateParts[1].padStart(2, '0')}`;

    // Calculate expiration date (1 year from last updated)
    const lastUpdatedDate = new Date(lastUpdatedIso);
    const expirationDate = addDays(lastUpdatedDate, 365).toISOString().split('T')[0];

    // Get data headers from row 3 (index 3)
    const headers = rows[3];

    console.log(`Hospital: ${hospitalName}`);
    console.log(`Last Updated: ${lastUpdatedIso}`);
    console.log(`Expiration Date: ${expirationDate}`);
    console.log('Converting to Transparency in Coverage In-Network Rates format...');

    // Build JSON
    const jsonOutput = {
        reporting_entity_name: hospitalName,
        reporting_entity_type: "hospital",
        last_updated_on: lastUpdatedIso,
        version: "v1.0.0",
        in_network: []
    };

    // Process each data row (starting from row 4, index 4)
    for (let i = 4; i < rows.length; i++) {
        const row = rows[i];

        // Skip empty rows
        if (row.length < 10 || !row[0] || row[0] === 'CPT_Code') {
            continue;
        }

        const cptCode = row[0].trim();
        const revCode = row[1].trim();
        const description = row[2].trim();
        const setting = row[3].trim();

        if (!cptCode) {
            continue;
        }

        // Determine billing code type
        const billingCodeType = cptCode.match(/^[HST]/) ? "HCPCS" : "CPT";

        // Collect negotiated rates for all payers
        const negotiatedRatesMap = {};

        // Process each payer column (starting from index 9)
        for (let j = 9; j < Math.min(row.length, headers.length); j++) {
            if (j >= headers.length) {
                break;
            }

            const payerHeader = headers[j].trim();
            const chargeValue = j < row.length ? parseNumber(row[j]) : null;

            if (chargeValue !== null && chargeValue > 0) {
                // Extract payer name (first word before underscore)
                const payerName = payerHeader.includes('_') ? 
                    payerHeader.split('_')[0] : payerHeader;
                const planName = payerHeader;

                // Group by payer for provider groups
                if (!negotiatedRatesMap[payerName]) {
                    negotiatedRatesMap[payerName] = {
                        plans: [],
                        rates: []
                    };
                }

                // Add this plan's rate
                negotiatedRatesMap[payerName].plans.push(planName);
                negotiatedRatesMap[payerName].rates.push({
                    negotiated_type: "fee schedule",
                    negotiated_rate: chargeValue,
                    expiration_date: expirationDate,
                    billing_class: setting === "Inpatient" ? "institutional" : "professional"
                });
            }
        }

        // Build negotiated_rates array
        const negotiatedRates = [];
        for (const [payerName, payerData] of Object.entries(negotiatedRatesMap)) {
            // Create a single negotiated rate entry per payer/plan
            for (let idx = 0; idx < payerData.plans.length; idx++) {
                negotiatedRates.push({
                    provider_groups: [{
                        npi: [],
                        tin: {
                            type: "ein",
                            value: licenseNumber
                        }
                    }],
                    negotiated_prices: [payerData.rates[idx]]
                });
            }
        }

        // Build the in-network entry
        const inNetworkEntry = {
            negotiation_arrangement: "ffs",
            name: description,
            billing_code_type: billingCodeType,
            billing_code_type_version: "2024",
            billing_code: cptCode,
            description: description,
            negotiated_rates: negotiatedRates
        };

        // Add rev code as modifier if present
        if (revCode && revCode !== "Not Applicable") {
            inNetworkEntry.billing_code_modifier = [revCode];
        }

        jsonOutput.in_network.push(inNetworkEntry);
    }

    console.log(`Processed ${jsonOutput.in_network.length} in-network entries`);
    console.log(`Total negotiated rates: ${jsonOutput.in_network.reduce((sum, e) => sum + e.negotiated_rates.length, 0)}`);

    // Write to JSON file
    fs.writeFileSync(outputJsonPath, JSON.stringify(jsonOutput, null, 2), 'utf-8');
    console.log(`\nJSON file created: ${outputJsonPath}`);

    return jsonOutput;
}

// Usage
if (require.main === module) {
    const inputCsvPath = process.argv[2] || 'Pricing_Transparency_file_Nov_2025_GA_Facility_.csv'; 

    const outputJsonPath = process.argv[3] || 'in_network_rates.json'; //2025-12-28_GA_Facility_in-network-rates.json

    try {
        convertCsvToInNetworkRates(inputCsvPath, outputJsonPath);
    } catch (error) {
        console.error('Error during conversion:', error);
        process.exit(1);
    }
}

module.exports = { convertCsvToInNetworkRates };
