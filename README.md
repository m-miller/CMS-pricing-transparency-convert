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
