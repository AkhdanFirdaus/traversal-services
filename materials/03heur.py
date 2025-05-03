import json
import re
from typing import Dict, List


class TraversalHeuristic:
    """
    Heuristic detector for directory traversal payloads.
    Loads named regex patterns from a JSON file and scans inputs.
    """

    def __init__(self, json_file: str):
        """
        :param json_file: Path to a JSON file containing a dict of
                          pattern_name -> regex (no delimiters).
        """
        with open(json_file, 'r', encoding='utf-8') as f:
            data = json.load(f)
        if not isinstance(data, dict):
            raise ValueError(f"Invalid JSON format in {json_file}, expected an object")
        # Compile regexes once
        self.patterns: Dict[str, re.Pattern] = {
            name: re.compile(f'({regex})', re.IGNORECASE | re.UNICODE)
            for name, regex in data.items()
        }

    def scan(self, input_str: str) -> List[str]:
        """
        Scan a single input string and return list of matching pattern names.
        """
        matches: List[str] = []
        for name, pattern in self.patterns.items():
            if pattern.search(input_str):
                matches.append(name)
        return matches

    def scan_batch(self, inputs: List[str]) -> Dict[int, List[str]]:
        """
        Scan a list of input strings. Returns a dict mapping
        input index -> list of matching pattern names.
        """
        return {i: self.scan(s) for i, s in enumerate(inputs)}


if __name__ == '__main__':
    # Example usage
    heuristic = TraversalHeuristic('patterns.json')

    samples = [
        '../etc/passwd',
        '%2e%2e/%2e%2e/etc/shadow',
        'normal.txt',
        'php://filter/convert.base64-encode/resource=config.php',
    ]

    report = heuristic.scan_batch(samples)
    for idx, matched in report.items():
        print(f"Sample [{idx}]: “{samples[idx]}” → "
              f"{', '.join(matched) if matched else 'no match'}")
