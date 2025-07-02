
## Specifications
[Here's the Specifications](/SPECIFICATION.md)

## Requirements
- Docker

## Run The Services
```bash
docker compose up -d
```

## Usage direct CLI
```bash
docker exec traversal-engine php main_cli.php <git-repo-url.git>
```

## Usage Server from CLI
```bash
# Check Engine Health
curl -X GET http://localhost:5003/test

# Run Engine
curl -X POST http://localhost:5003/process -H "Content-Type: application/json" -d '{"roomName":"<freetext>","gitUrl":"<git-repo-url.git>"}'
```


## Result
Result will be stored in `outputs/<roomName>` folder, will be contains:

| File Name | Description |
|-----------|-------------|
| git-lsfiles-output.txt | cloned project structure |
| msi-original.json | raw output of first mutation testing |
| msi-initial.json | formatted output of first mutation testing |
| phpunit-initial.json | abstract syntax tree from phpunit testing |
| (optional) generated-results.json | new test case from LLM based on mutation test |
| (optional) generated-results.zip | exported downloadable new test case |