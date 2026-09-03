<?php

declare(strict_types=1);

namespace App;

use Aws\Exception\AwsException;
use Aws\Lambda\LambdaClient;
use Aws\S3\S3Client;
use Aws\SecretsManager\SecretsManagerClient;
use Aws\Sqs\SqsClient;
use PDO;
use PDOException;

final class AwsDemoService
{
    public function __construct(private readonly Config $config)
    {
    }

    public function testRdsConnection(): array
    {
        $dsn = $this->config->get('RDS_DSN');
        $username = $this->config->get('RDS_USER');
        $password = $this->config->get('RDS_PASSWORD');

        if (!$dsn || !$username) {
            return [
                'ok' => false,
                'message' => 'RDS_DSN e RDS_USER são obrigatórios para o teste de conexão.',
            ];
        }

        try {
            $pdo = $this->createPdoConnection();

            $stmt = $pdo->query('SELECT NOW() AS server_time');
            $row = $stmt->fetch();

            return [
                'ok' => true,
                'message' => 'Conexão com RDS realizada com sucesso.',
                'data' => [
                    'server_time' => $row['server_time'] ?? null,
                    'dsn' => $dsn,
                ],
            ];
        } catch (PDOException $exception) {
            return [
                'ok' => false,
                'message' => 'Falha ao conectar no RDS: ' . $exception->getMessage(),
            ];
        }
    }

    public function getInstanceMetadata(): array
    {
        $token = $this->fetchImdsV2Token();
        if ($token === null) {
            return [
                'ok' => false,
                'message' => 'Não foi possível obter token IMDSv2. Verifique se a app está em EC2 e com acesso ao metadata service.',
            ];
        }

        $instanceId = $this->fetchMetadataPath('instance-id', $token);
        $privateIp = $this->fetchMetadataPath('local-ipv4', $token);
        $availabilityZone = $this->fetchMetadataPath('placement/availability-zone', $token);
        $region = $this->fetchMetadataPath('placement/region', $token);

        return [
            'ok' => true,
            'message' => 'Metadados da instância carregados.',
            'data' => [
                'instance_id' => $instanceId,
                'private_ip' => $privateIp,
                'availability_zone' => $availabilityZone,
                'region' => $region,
                'hostname' => gethostname() ?: null,
                'app_env' => $this->config->get('APP_ENV', 'dev'),
                'collected_at' => gmdate('c'),
            ],
        ];
    }

    public function startAutoscalingStressTest(int $seconds = 300, int $workers = 2): array
    {
        $seconds = max(0, min(3600, $seconds));
        $workers = max(1, min(8, $workers));

        $stopFile = $this->getAutoscalingStopFile();
        @unlink($stopFile);

        $pids = [];
        for ($i = 0; $i < $workers; $i++) {
            $logPath = sprintf('/tmp/upperstack-stress-%d.log', $i);

            if ($seconds === 0) {
                $command = sprintf(
                    "nohup sh -c %s >%s 2>&1 & echo $!",
                    escapeshellarg('exec yes > /dev/null'),
                    escapeshellarg($logPath)
                );
            } else {
                $command = sprintf(
                    "nohup sh -c %s >%s 2>&1 & echo $!",
                    escapeshellarg(sprintf('exec timeout %d yes > /dev/null', $seconds)),
                    escapeshellarg($logPath)
                );
            }

            $pid = trim((string) shell_exec($command));
            if ($pid !== '' && ctype_digit($pid)) {
                $pids[] = (int) $pid;
            }
        }

        if ($pids === []) {
            return [
                'ok' => false,
                'message' => 'Não foi possível iniciar o processo de estresse. Verifique permissões do shell/PHP.',
            ];
        }

        $pidFile = $this->getAutoscalingPidFile();
        file_put_contents($pidFile, implode("
", $pids) . "
");

        return [
            'ok' => true,
            'message' => 'Teste de estresse iniciado em background.',
            'data' => [
                'workers' => count($pids),
                'duration_seconds' => $seconds,
                'running_mode' => $seconds === 0 ? 'infinite_until_stop' : 'timed',
                'pids' => $pids,
                'pid_file' => $pidFile,
                'stop_file' => $stopFile,
                'note' => 'Monitore CPU da instância no CloudWatch/ASG para observar scale out.',
            ],
        ];
    }

    public function stopAutoscalingStressTest(): array
    {
        $pidFile = $this->getAutoscalingPidFile();
        @file_put_contents($this->getAutoscalingStopFile(), (string) time());
        if (!is_file($pidFile)) {
            return [
                'ok' => false,
                'message' => 'Nenhum teste em execução foi encontrado (arquivo de PID ausente).',
            ];
        }

        $rawPids = file($pidFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $pids = array_values(array_filter(array_map(static function (string $pid): int {
            return ctype_digit(trim($pid)) ? (int) trim($pid) : 0;
        }, $rawPids)));

        if ($pids === []) {
            @unlink($pidFile);
            @unlink($this->getAutoscalingStopFile());
            return [
                'ok' => false,
                'message' => 'Arquivo de PID inválido ou vazio.',
            ];
        }

        $killed = [];
        $notFound = [];

        foreach ($pids as $pid) {
            exec(sprintf('kill -15 %d >/dev/null 2>&1', $pid), $output, $exitCode);
            if ($exitCode !== 0) {
                $notFound[] = $pid;
                continue;
            }

            usleep(150000);
            exec(sprintf('kill -0 %d >/dev/null 2>&1', $pid), $probeOutput, $probeCode);
            if ($probeCode === 0) {
                exec(sprintf('kill -9 %d >/dev/null 2>&1', $pid));
            }

            $killed[] = $pid;
        }

        @unlink($pidFile);
        @unlink($this->getAutoscalingStopFile());

        return [
            'ok' => true,
            'message' => 'Comando de parada executado.',
            'data' => [
                'killed_pids' => $killed,
                'not_found_pids' => $notFound,
            ],
        ];
    }

    public function listUsers(string $connectionSource = 'env'): array
    {
        try {
            $pdo = $this->createPdoConnection($connectionSource);
            $stmt = $pdo->query('SELECT id, name, email, created_at, updated_at FROM users ORDER BY id DESC');
            $rows = $stmt->fetchAll();

            return [
                'ok' => true,
                'message' => 'Lista de usuários carregada com sucesso.',
                'data' => [
                    'users' => $rows,
                ],
            ];
        } catch (PDOException $exception) {
            return [
                'ok' => false,
                'message' => 'Erro ao listar usuários: ' . $exception->getMessage(),
            ];
        }
    }

    public function createUser(string $name, string $email, string $connectionSource = 'env'): array
    {
        if ($name === '' || $email === '') {
            return [
                'ok' => false,
                'message' => 'Nome e e-mail são obrigatórios.',
            ];
        }

        try {
            $pdo = $this->createPdoConnection($connectionSource);
            $stmt = $pdo->prepare('INSERT INTO users (name, email) VALUES (:name, :email)');
            $stmt->execute([
                'name' => $name,
                'email' => $email,
            ]);

            return [
                'ok' => true,
                'message' => 'Usuário criado com sucesso.',
                'data' => [
                    'id' => (int) $pdo->lastInsertId(),
                    'name' => $name,
                    'email' => $email,
                ],
            ];
        } catch (PDOException $exception) {
            return [
                'ok' => false,
                'message' => 'Erro ao criar usuário: ' . $exception->getMessage(),
            ];
        }
    }

    public function updateUser(int $id, string $name, string $email, string $connectionSource = 'env'): array
    {
        if ($id <= 0 || $name === '' || $email === '') {
            return [
                'ok' => false,
                'message' => 'ID, nome e e-mail são obrigatórios.',
            ];
        }

        try {
            $pdo = $this->createPdoConnection($connectionSource);
            $stmt = $pdo->prepare('UPDATE users SET name = :name, email = :email, updated_at = NOW() WHERE id = :id');
            $stmt->execute([
                'id' => $id,
                'name' => $name,
                'email' => $email,
            ]);

            if ($stmt->rowCount() === 0) {
                return [
                    'ok' => false,
                    'message' => 'Usuário não encontrado para atualização.',
                ];
            }

            return [
                'ok' => true,
                'message' => 'Usuário atualizado com sucesso.',
            ];
        } catch (PDOException $exception) {
            return [
                'ok' => false,
                'message' => 'Erro ao atualizar usuário: ' . $exception->getMessage(),
            ];
        }
    }

    public function deleteUser(int $id, string $connectionSource = 'env'): array
    {
        if ($id <= 0) {
            return [
                'ok' => false,
                'message' => 'ID do usuário é obrigatório.',
            ];
        }

        try {
            $pdo = $this->createPdoConnection($connectionSource);
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
            $stmt->execute(['id' => $id]);

            if ($stmt->rowCount() === 0) {
                return [
                    'ok' => false,
                    'message' => 'Usuário não encontrado para exclusão.',
                ];
            }

            return [
                'ok' => true,
                'message' => 'Usuário excluído com sucesso.',
            ];
        } catch (PDOException $exception) {
            return [
                'ok' => false,
                'message' => 'Erro ao excluir usuário: ' . $exception->getMessage(),
            ];
        }
    }

    public function loadSecret(): array
    {
        try {
            [$secretId, $secretString] = $this->fetchSecretString();
            $payload = $this->decodeSecretPayload($secretString);

            return [
                'ok' => true,
                'message' => 'Secret carregado com sucesso.',
                'data' => [
                    'secret_id' => $secretId,
                    'secret_preview' => substr($secretString, 0, 120),
                    'secret_keys' => array_keys($payload),
                ],
            ];
        } catch (AwsException|PDOException $exception) {
            return [
                'ok' => false,
                'message' => 'Erro ao consultar o Secrets Manager: ' . $exception->getMessage(),
            ];
        }
    }

    public function testRdsConnectionUsingSecret(): array
    {
        try {
            [$secretId, $secretString] = $this->fetchSecretString();
            $payload = $this->decodeSecretPayload($secretString);
            $credentials = $this->extractRdsCredentialsFromSecret($payload);
            $pdo = $this->openPdoConnection(
                $credentials['dsn'],
                $credentials['username'],
                $credentials['password']
            );

            $stmt = $pdo->query('SELECT NOW() AS server_time');
            $row = $stmt->fetch();

            return [
                'ok' => true,
                'message' => 'Conexão com RDS via Secrets Manager realizada com sucesso.',
                'data' => [
                    'secret_id' => $secretId,
                    'server_time' => $row['server_time'] ?? null,
                    'dsn' => $credentials['dsn'],
                    'username' => $credentials['username'],
                ],
            ];
        } catch (AwsException|PDOException $exception) {
            return [
                'ok' => false,
                'message' => 'Falha ao conectar no RDS via secret: ' . $exception->getMessage(),
            ];
        }
    }

    public function uploadFileToS3(array $file): array
    {
        return $this->uploadToS3($file, 'uploads/', false);
    }

    public function uploadLogoToS3(array $file): array
    {
        return $this->uploadToS3($file, 'branding/', true);
    }

    public function getLogoUrlFromS3(): array
    {
        $bucket = $this->config->get('S3_BUCKET');
        if (!$bucket) {
            return [
                'ok' => false,
                'message' => 'S3_BUCKET não foi definido.',
            ];
        }

        $client = new S3Client([
            'version' => 'latest',
            'region' => $this->config->get('AWS_REGION', 'us-east-1'),
        ]);

        try {
            $result = $client->listObjectsV2([
                'Bucket' => $bucket,
                'Prefix' => 'branding/',
                'MaxKeys' => 30,
            ]);

            $items = $result['Contents'] ?? [];
            if (!$items) {
                return [
                    'ok' => false,
                    'message' => 'Nenhum logo encontrado no bucket.',
                ];
            }

            usort($items, static function (array $a, array $b): int {
                $timeA = isset($a['LastModified']) ? strtotime((string) $a['LastModified']) : 0;
                $timeB = isset($b['LastModified']) ? strtotime((string) $b['LastModified']) : 0;
                return $timeB <=> $timeA;
            });

            $logoKey = (string) ($items[0]['Key'] ?? '');
            if ($logoKey === '') {
                return [
                    'ok' => false,
                    'message' => 'Logo não encontrado no prefixo branding/.',
                ];
            }

            $command = $client->getCommand('GetObject', [
                'Bucket' => $bucket,
                'Key' => $logoKey,
            ]);

            $request = $client->createPresignedRequest($command, '+15 minutes');
            $logoUrl = (string) $request->getUri();

            return [
                'ok' => true,
                'message' => 'Logo carregado com sucesso.',
                'data' => [
                    'bucket' => $bucket,
                    'key' => $logoKey,
                    'logo_url' => $logoUrl,
                ],
            ];
        } catch (AwsException $exception) {
            return [
                'ok' => false,
                'message' => 'Erro ao buscar logo no S3: ' . $exception->getAwsErrorMessage(),
            ];
        }
    }

    private function uploadToS3(array $file, string $prefix, bool $imageOnly): array
    {
        $bucket = $this->config->get('S3_BUCKET');
        if (!$bucket) {
            return [
                'ok' => false,
                'message' => 'S3_BUCKET não foi definido.',
            ];
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return [
                'ok' => false,
                'message' => 'Arquivo inválido para upload.',
            ];
        }

        $fileName = basename((string) ($file['name'] ?? 'upload.bin'));
        $mimeType = (string) ($file['type'] ?? '');
        if ($imageOnly && !str_starts_with($mimeType, 'image/')) {
            return [
                'ok' => false,
                'message' => 'Para logo, envie apenas arquivos de imagem.',
            ];
        }

        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '-', $fileName) ?: 'arquivo.bin';
        $key = sprintf('%s%s-%s', $prefix, date('Ymd-His'), $safeName);

        $client = new S3Client([
            'version' => 'latest',
            'region' => $this->config->get('AWS_REGION', 'us-east-1'),
        ]);

        try {
            $client->putObject([
                'Bucket' => $bucket,
                'Key' => $key,
                'SourceFile' => $file['tmp_name'],
                'ACL' => 'private',
                'ContentType' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
            ]);

            return [
                'ok' => true,
                'message' => 'Upload para S3 concluído.',
                'data' => [
                    'bucket' => $bucket,
                    'key' => $key,
                ],
            ];
        } catch (AwsException $exception) {
            return [
                'ok' => false,
                'message' => 'Erro no upload para S3: ' . $exception->getAwsErrorMessage(),
            ];
        }
    }

    public function triggerLambdaAndQueue(string $message): array
    {
        $region = $this->config->get('AWS_REGION', 'us-east-1');
        $lambdaArn = $this->config->get('LAMBDA_INVOKE_ARN');
        $queueUrl = $this->config->get('SQS_QUEUE_URL');

        if (!$lambdaArn || !$queueUrl) {
            return [
                'ok' => false,
                'message' => 'LAMBDA_INVOKE_ARN e SQS_QUEUE_URL são obrigatórios.',
            ];
        }

        $decoded = json_decode($message, true);
        $orderPayload = is_array($decoded) ? $decoded : [
            'order_id' => sprintf('ORD-%s', date('YmdHis')),
            'customer_name' => 'Cliente Upperstack',
            'customer_email' => 'demo@upperstack.com.br',
            'total_amount' => 0,
            'items_count' => 1,
            'notes' => $message,
        ];

        $payload = json_encode($orderPayload, JSON_THROW_ON_ERROR);

        $lambdaClient = new LambdaClient([
            'version' => 'latest',
            'region' => $region,
        ]);

        $sqsClient = new SqsClient([
            'version' => 'latest',
            'region' => $region,
        ]);

        try {
            $lambdaResult = $lambdaClient->invoke([
                'FunctionName' => $lambdaArn,
                'InvocationType' => 'Event',
                'Payload' => $payload,
            ]);

            $sqsResult = $sqsClient->sendMessage([
                'QueueUrl' => $queueUrl,
                'MessageBody' => $payload,
            ]);

            return [
                'ok' => true,
                'message' => 'Mensagem enviada para Lambda e SQS.',
                'data' => [
                    'lambda_status' => $lambdaResult->get('StatusCode'),
                    'sqs_message_id' => $sqsResult->get('MessageId'),
                ],
            ];
        } catch (AwsException $exception) {
            return [
                'ok' => false,
                'message' => 'Erro ao acionar Lambda/SQS: ' . $exception->getAwsErrorMessage(),
            ];
        }
    }

    public function pollProcessedQueue(): array
    {
        $processedQueueUrl = $this->config->get('SQS_PROCESSED_QUEUE_URL');

        if (!$processedQueueUrl) {
            return [
                'ok' => false,
                'message' => 'SQS_PROCESSED_QUEUE_URL não foi definido.',
            ];
        }

        $client = new SqsClient([
            'version' => 'latest',
            'region' => $this->config->get('AWS_REGION', 'us-east-1'),
        ]);

        try {
            $result = $client->receiveMessage([
                'QueueUrl' => $processedQueueUrl,
                'MaxNumberOfMessages' => 5,
                'WaitTimeSeconds' => 1,
            ]);

            $messages = $result->get('Messages') ?? [];

            return [
                'ok' => true,
                'message' => 'Consulta da fila processada finalizada.',
                'data' => [
                    'count' => count($messages),
                    'messages' => array_map(static fn(array $item): array => [
                        'id' => $item['MessageId'] ?? null,
                        'body' => $item['Body'] ?? null,
                    ], $messages),
                ],
            ];
        } catch (AwsException $exception) {
            return [
                'ok' => false,
                'message' => 'Erro ao consultar a fila processada: ' . $exception->getAwsErrorMessage(),
            ];
        }
    }

    public function explainConfiguration(): array
    {
        return [
            'dotenv_keys' => $this->config->allForPrefix('DEMO_'),
            'environment_example' => [
                'AWS_REGION' => $this->config->get('AWS_REGION', 'us-east-1'),
                'APP_ENV' => $this->config->get('APP_ENV', 'dev'),
                'EC2_INSTANCE_ID' => $this->config->get('EC2_INSTANCE_ID', 'definir via user-data'),
            ],
        ];
    }


    private function getAutoscalingPidFile(): string
    {
        return '/tmp/upperstack-autoscaling-stress.pids';
    }

    private function getAutoscalingStopFile(): string
    {
        return '/tmp/upperstack-autoscaling-stress.stop';
    }

    private function createPdoConnection(string $connectionSource = 'env'): PDO
    {
        if ($connectionSource === 'secret') {
            [, $secretString] = $this->fetchSecretString();
            $payload = $this->decodeSecretPayload($secretString);
            $credentials = $this->extractRdsCredentialsFromSecret($payload);

            return $this->openPdoConnection(
                $credentials['dsn'],
                $credentials['username'],
                $credentials['password']
            );
        }

        $dsn = $this->config->get('RDS_DSN');
        $username = $this->config->get('RDS_USER');
        $password = $this->config->get('RDS_PASSWORD');

        if (!$dsn || !$username) {
            throw new PDOException('RDS_DSN e RDS_USER são obrigatórios.');
        }

        return $this->openPdoConnection($dsn, $username, (string) $password);
    }

    private function openPdoConnection(string $dsn, string $username, string $password): PDO
    {
        return new PDO($dsn, $username, (string) $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private function fetchSecretString(): array
    {
        $secretId = $this->config->get('AWS_SECRET_ID');
        if (!$secretId) {
            throw new PDOException('AWS_SECRET_ID não foi definido.');
        }

        $client = new SecretsManagerClient([
            'version' => 'latest',
            'region' => $this->config->get('AWS_REGION', 'us-east-1'),
        ]);

        $result = $client->getSecretValue(['SecretId' => $secretId]);
        $secretString = (string) ($result['SecretString'] ?? '');
        if ($secretString === '') {
            throw new PDOException('O secret não possui SecretString.');
        }

        return [$secretId, $secretString];
    }

    private function decodeSecretPayload(string $secretString): array
    {
        $payload = json_decode($secretString, true);
        if (!is_array($payload)) {
            throw new PDOException('SecretString precisa ser um JSON com as credenciais de acesso.');
        }

        return $payload;
    }

    private function extractRdsCredentialsFromSecret(array $payload): array
    {
        $dsn = trim((string) ($payload['dsn'] ?? ''));
        $username = trim((string) ($payload['username'] ?? $payload['user'] ?? ''));
        $password = (string) ($payload['password'] ?? '');

        if ($dsn === '') {
            $engine = strtolower(trim((string) ($payload['engine'] ?? 'mysql')));
            $host = trim((string) ($payload['host'] ?? ''));
            $port = (int) ($payload['port'] ?? ($engine === 'pgsql' ? 5432 : 3306));
            $database = trim((string) ($payload['dbname'] ?? $payload['database'] ?? ''));

            if ($host === '' || $database === '') {
                throw new PDOException('Secret deve conter "dsn" ou as chaves "host" e "dbname/database".');
            }

            if ($engine === 'pgsql' || $engine === 'postgres' || $engine === 'postgresql') {
                $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $database);
            } else {
                $charset = trim((string) ($payload['charset'] ?? 'utf8mb4'));
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);
            }
        }

        if ($username === '') {
            throw new PDOException('Secret deve conter "username" (ou "user").');
        }

        return [
            'dsn' => $dsn,
            'username' => $username,
            'password' => $password,
        ];
    }

    private function fetchImdsV2Token(): ?string
    {
        $ch = curl_init('http://169.254.169.254/latest/api/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_HTTPHEADER => ['X-aws-ec2-metadata-token-ttl-seconds: 21600'],
        ]);

        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status !== 200 || !is_string($result) || trim($result) === '') {
            return null;
        }

        return trim($result);
    }

    private function fetchMetadataPath(string $path, string $token): ?string
    {
        $url = 'http://169.254.169.254/latest/meta-data/' . ltrim($path, '/');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_HTTPHEADER => ['X-aws-ec2-metadata-token: ' . $token],
        ]);

        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status !== 200 || !is_string($result) || trim($result) === '') {
            return null;
        }

        return trim($result);
    }
}
