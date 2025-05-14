exports.buildTestPrompt = (vulnReport) => {
  return `
You are a security-focused AI assistant. Given the following PHP vulnerability context, generate a PHPUnit-compatible test case that attempts to exploit or validate the identified issue.

Context:
${JSON.stringify(vulnReport, null, 2)}

Make sure to:
- Use proper PHP syntax
- Include '@test' or 'public function test...()' methods
- Target the related vulnerable method or file
- Don't fix the vulnerability, only try to test it

Return ONLY the test code in your response.
`;
};
