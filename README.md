## Accanto CMS Pricing Transparency

- Export needed pricing transparency Excel sheets as CSV files. Column/row format must not be changed from current format.
- Convert using JavaScript or PHP convert scripts
- Update table_of_contents file with converted json files (NC & GA, etc) and date
- Update HTML tables with current data
- Upload json files and Table of Contents json file to same directory, currently in the public_html web root

## JavaScript/Node.js Usage

- update output line 178 to .json filename
- update CSV file input filename

### Prerequisites
```bash
node v23.0.0
```

```bash
npm install csv-parse
```

### Command Line Usage
```bash
node convert_to_in_network_rates.js input.csv output.json
```

### As a Module
```javascript
const { convertCsvToInNetworkRates } = require('./convert_to_in_network_rates.js');

convertCsvToInNetworkRates('input.csv', 'output.json');
```
