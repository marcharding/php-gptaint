# GPTaint

## Comparing Static Taint Analyzers Against Large Language Models (LLMs)

This PHP application provides a basic analysis suite designed to test the performance of large language models (LLMs) against static analyzers in identifying security vulnerabilities in PHP code. Currently, it operates on single files from the test set; however, it could be adapted to scan real codebases by extracting relevant code fragments and using these as samples. Preliminary work has already started in the `App\Service\CodeExtractor` namespace.

## Setup & Configuration

The application is fully dockerized. To install and set up, follow the typical Docker setup instructions:

### Settings and API Tokens

Your API tokens must be set in your `.env` file. Refer to the included `.env` file for details, and place your tokens/settings in a new `.env.local` file.

### Using Colima on macOS:

```bash
colima start --vm-type=vz --mount-type=virtiofs --cpu 4 --memory 4
docker compose up --remove-orphans
```

To execute commands within the main app container, use:

```bash
docker exec -it webserver-app bash
```

Alternatively, execute the commands via Docker Compose. The following examples demonstrate this approach.

### Create the Database Schema

```bash
docker compose exec webserver_app php bin/console doctrine:schema:create --force
```

### Running the Tests

1. Download the samples from the NIST database:

```bash
docker compose exec webserver_app php bin/console app:samples:download
```

2. Extract the samples:

```bash
docker compose exec webserver_app php bin/console app:samples:extract
```

3. Generate a randomized test set or load an existing test set:

```bash
docker compose exec webserver_app php bin/console app:samples:create-randomize-test-set
docker compose exec webserver_app php bin/console app:samples:create-randomize-test-set /var/www/application/data/samples-all/nist/extracted/2022-05-12-php-test-suite-sqli-v1-0-0 /var/www/application/data/samples-selection/2022-05-12-php-test-suite-sqli-v1-0-0-samples --amount=100
docker compose exec webserver_app php bin/console app:samples:create-randomize-test-set /var/www/application/data/samples-all/nist/extracted/2022-08-02-php-test-suite-xss-v1-0-0 /var/www/application/data/samples-selection/2022-08-02-php-test-suite-xss-v1-0-0 --amount=500
docker compose exec webserver_app php bin/console app:sample:analyze:static --analyzeTypes=phan,psalm,snyk  /var/www/application/data/samples-selection/2022-08-02-php-test-suite-xss-v1-0-0
```

```bash
docker compose exec webserver_app php bin/console app:samples:load-samples-preset
```

4. Analyze the sample with static analyzers:

```bash
docker compose exec webserver_app php bin/console app:sample:analyze:static --analyzeTypes=phan,psalm /var/www/application/data/samples-all/nist/foobar

```

5. Analyze the samples with online LLMs (currently supporting OpenAI or Mistral):

```bash
docker compose exec webserver_app php bin/console app:sample:analyze:llm --model=gpt-3.5-turbo-0125
docker compose exec webserver_app php bin/console app:sample:analyze:llm --model=gpt-3.5-turbo-0125 --randomized
docker compose exec webserver_app php bin/console app:sample:analyze:llm --model=gpt-4-0125-preview
docker compose exec webserver_app php bin/console app:sample:analyze:llm --model=gpt-4-0125-preview --randomized
docker compose exec webserver_app php bin/console app:sample:analyze:llm --model=gpt-turbo
docker compose exec webserver_app php bin/console app:sample:analyze:llm --model=gpt-turbo --randomized
docker compose exec webserver_app php bin/console app:sample:analyze:llm --model=gpt-4o
docker compose exec webserver_app php bin/console app:sample:analyze:llm --model=gpt-4o --randomized
```

Analyse a sample again by providing the id

```bash
docker compose exec webserver_app php bin/console app:sample:analyze:llm --model=gpt-4o 10
docker compose exec webserver_app php bin/console app:sample:analyze:llm --model=gpt-4o --randomized 10
```

## Using Other LLMs with OpenAI Compatible APIs (e.g., LlamaCPP)

### Start Llama via LlamaCPP (https://github.com/ggerganov/llama.cpp)

```bash
./llama-server --ctx-size 8192 -m models/Meta-Llama-3-8B-Instruct-Q6_K.gguf
./llama-server --ctx-size 4096 -m models/Phi-3-mini-4k-instruct-q4.gguf
```
or just use the provided docker image

```bash
wget https://huggingface.co/bartowski/Llama-3.3-70B-Instruct-GGUF/resolve/main/Llama-3.3-70B-Instruct-Q6_K_L/Llama-3.3-70B-Instruct-Q6_K_L-00001-of-00002.gguf
wget https://huggingface.co/bartowski/Llama-3.3-70B-Instruct-GGUF/resolve/main/Llama-3.3-70B-Instruct-Q6_K_L/Llama-3.3-70B-Instruct-Q6_K_L-00002-of-00002.gguf
docker run -v /root/models:/models -p 8080:8080 ghcr.io/ggerganov/llama.cpp:server-cuda -m /models/Llama-3.3-70B-Instruct-Q6_K_L-00001-of-00002.gguf --port 8080 --flash-attn --ctx-size 65536 --host 0.0.0.0 --n-gpu-layers 128
```

### Run Tests for the Specific LLM

```bash
docker compose exec webserver_app php bin/console app:sample:analyze:llm --model=llama.cpp/llama-32-8b --randomized
```

### Helper Commands:

To get statistics on the used contexts, sinks, etc.:

```bash
docker compose exec webserver_app php bin/console app:nist:stats /var/www/application/data/nist/samples_all/2022-05-12-php-test-suite-sqli-v1-0-0
docker compose exec webserver_app php bin/console app:nist:stats /var/www/application/data/nist/samples_all/2022-08-02-php-test-suite-xss-v1-0-0
```

To export results:

```bash
docker compose exec webserver_app php bin/console app:sample:results:export:csv results.csv
docker compose exec webserver_app php bin/console app:analysis:results:export:csv --randomized
docker compose exec webserver_app php bin/console app:analysis:results:export:csv --no-randomized
docker compose exec webserver_app php bin/console app:analysis:results:export:csv --feedback
docker compose exec webserver_app php bin/console app:analysis:results:export:csv --no-feedback
docker compose exec webserver_app php bin/console app:analysis:results:export:csv --randomized --feedback
docker compose exec webserver_app php bin/console app:analysis:results:export:csv --randomized --no-feedback
docker compose exec webserver_app php bin/console app:analysis:results:export:csv --no-randomized --feedback
docker compose exec webserver_app php bin/console app:analysis:results:export:csv --no-randomized --no-feedback
docker compose exec webserver_app php bin/console app:analysis:results:export:csv --metrics="truePositives,trueNegatives,falsePositives,falseNegatives,recall,specificity,f1"
docker compose exec webserver_app php bin/console app:analysis:results:export:csv --analyzer="gpt-4o (randomized),gpt-4o (randomized)_wo_feedback,llama-3-8b (randomized),llama-3-8b (randomized)_wo_feedback,phan,psalm,snyk"
docker compose exec webserver_app php bin/console app:analysis:results:export:csv --analyzer="psalm,snyk,phan,gpt-3.5-turbo (randomized),gpt-3.5-turbo (randomized)_wo_feedback,llama-32-8b (randomized),llama-32-8b (randomized)_wo_feedback,gpt-4o-mini (randomized),gpt-4o-mini (randomized)_wo_feedback,gpt-4o (randomized),gpt-4o (randomized)_wo_feedback"  --metrics="truePositives,trueNegatives,falsePositives,falseNegatives,recall,specificity,f1,far,costs,time"
```

To export detailed results per issue:

```bash
docker compose exec webserver_app php bin/console app:analysis:results:per:issue:export:csv --randomized
docker compose exec webserver_app php bin/console app:analysis:results:per:issue:export:csv --no-randomized
docker compose exec webserver_app php bin/console app:analysis:results:per:issue:export:csv --feedback
docker compose exec webserver_app php bin/console app:analysis:results:per:issue:export:csv --no-feedback
docker compose exec webserver_app php bin/console app:analysis:results:per:issue:export:csv --randomized --feedback
docker compose exec webserver_app php bin/console app:analysis:results:per:issue:export:csv --randomized --no-feedback
docker compose exec webserver_app php bin/console app:analysis:results:per:issue:export:csv --no-randomized --feedback
docker compose exec webserver_app php bin/console app:analysis:results:per:issue:export:csv --no-randomized --no-feedback
docker compose exec webserver_app php bin/console app:analysis:results:per:issue:export:csv --analyzer="gpt-4o (randomized),gpt-4o (randomized)_wo_feedback,llama-3-8b (randomized),llama-3-8b (randomized)_wo_feedback,phan,psalm,snyk"
docker compose exec webserver_app php bin/console app:analysis:results:per:issue:export:csv --analyzer="gpt-4o (randomized),gpt-4o (randomized)_wo_feedback,psalm"
docker compose exec webserver_app php bin/console app:analysis:results:per:issue:export:csv --analyzer="gpt-4o (randomized),gpt-4o (randomized)_wo_feedback,psalm"
docker compose exec webserver_app php bin/console app:analysis:results:per:issue:export:csv --analyzer="psalm,snyk,phan,gpt-3.5-turbo (randomized),gpt-3.5-turbo (randomized)_wo_feedback,llama-32-8b (randomized),llama-32-8b (randomized)_wo_feedback,gpt-4o-mini (randomized),gpt-4o-mini (randomized)_wo_feedback,gpt-4o (randomized),gpt-4o (randomized)_wo_feedback"
```
