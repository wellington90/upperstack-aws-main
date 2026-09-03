<?php

declare(strict_types=1);

use App\AwsDemoService;
use App\Config;

require_once __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');

$config = new Config();
$config->loadDotenvIfPresent(dirname(__DIR__));
$service = new AwsDemoService($config);

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'rds':
            respond($service->testRdsConnection());
            break;

        case 'secret':
            respond($service->loadSecret());
            break;
        case 'rds-secret':
            respond($service->testRdsConnectionUsingSecret());
            break;

        case 'config':
            respond([
                'ok' => true,
                'message' => 'Leitura de configurações executada.',
                'data' => $service->explainConfiguration(),
            ]);
            break;

        case 'metadata':
            respond($service->getInstanceMetadata());
            break;

        case 's3-upload':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                respond(['ok' => false, 'message' => 'Método inválido.'], 405);
                break;
            }

            respond($service->uploadFileToS3($_FILES['file'] ?? []));
            break;

        case 'logo-upload':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                respond(['ok' => false, 'message' => 'Método inválido.'], 405);
                break;
            }

            respond($service->uploadLogoToS3($_FILES['file'] ?? []));
            break;

        case 'logo':
            respond($service->getLogoUrlFromS3());
            break;

        case 'send-message':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                respond(['ok' => false, 'message' => 'Método inválido.'], 405);
                break;
            }

            $body = json_decode(file_get_contents('php://input') ?: '{}', true);
            $message = trim((string) ($body['message'] ?? 'Mensagem de demonstração'));
            respond($service->triggerLambdaAndQueue($message));
            break;

        case 'autoscaling-test':
            ensureMethod('POST');
            $body = readJsonBody();
            $seconds = (int) ($body['seconds'] ?? 300);
            $workers = (int) ($body['workers'] ?? 2);
            respond($service->startAutoscalingStressTest($seconds, $workers));
            break;

        case 'autoscaling-stop':
            ensureMethod('POST');
            respond($service->stopAutoscalingStressTest());
            break;

        case 'poll-processed':
            respond($service->pollProcessedQueue());
            break;

        case 'users-list':
            $source = normalizeConnectionSource($_GET['source'] ?? 'env');
            respond($service->listUsers($source));
            break;

        case 'users-create':
            ensureMethod('POST');
            $body = readJsonBody();
            $name = trim((string) ($body['name'] ?? ''));
            $email = trim((string) ($body['email'] ?? ''));
            $source = normalizeConnectionSource($body['source'] ?? 'env');
            respond($service->createUser($name, $email, $source));
            break;

        case 'users-update':
            ensureMethod('PUT');
            $body = readJsonBody();
            $id = (int) ($body['id'] ?? 0);
            $name = trim((string) ($body['name'] ?? ''));
            $email = trim((string) ($body['email'] ?? ''));
            $source = normalizeConnectionSource($body['source'] ?? 'env');
            respond($service->updateUser($id, $name, $email, $source));
            break;

        case 'users-delete':
            ensureMethod('DELETE');
            $body = readJsonBody();
            $id = (int) ($body['id'] ?? 0);
            $source = normalizeConnectionSource($body['source'] ?? 'env');
            respond($service->deleteUser($id, $source));
            break;

        default:
            respond(['ok' => false, 'message' => 'Ação inválida.'], 404);
            break;
    }
} catch (Throwable $throwable) {
    respond([
        'ok' => false,
        'message' => 'Erro inesperado: ' . $throwable->getMessage(),
    ], 500);
}

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function ensureMethod(string $expectedMethod): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $expectedMethod) {
        respond(['ok' => false, 'message' => 'Método inválido.'], 405);
        exit;
    }
}

function readJsonBody(): array
{
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    return is_array($body) ? $body : [];
}

function normalizeConnectionSource(mixed $source): string
{
    return $source === 'secret' ? 'secret' : 'env';
}
