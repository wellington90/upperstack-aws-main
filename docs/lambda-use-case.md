# Lambdas didáticas (caso real): fluxo de pedidos de e-commerce

Este projeto inclui dois exemplos de Lambda para aula com um cenário mais próximo do mundo real:

1. `lambda/order-intake/index.mjs`
   - recebe um pedido,
   - registra evento na fila SQS de entrada,
   - invoca a lambda de processamento.

2. `lambda/order-processor/index.mjs`
   - aplica uma regra simples de prioridade,
   - publica o resultado na fila SQS de saída,
   - permite que o front na EC2 consulte o status.

## Variáveis necessárias

### order-intake
- `AWS_REGION`
- `ORDER_PROCESSOR_ARN`
- `SQS_INPUT_QUEUE_URL`

### order-processor
- `AWS_REGION`
- `SQS_OUTPUT_QUEUE_URL`

## Sugestão de ligação com a app

- No `.env` da EC2, configure:
  - `LAMBDA_INVOKE_ARN` apontando para `order-intake`
  - `SQS_QUEUE_URL` para a fila de entrada
  - `SQS_PROCESSED_QUEUE_URL` para a fila de saída

Assim, o botão "Acionar Lambda + enviar para SQS" passa a demonstrar o fluxo completo.

## Payload sugerido no front

No campo de mensagem, você pode informar texto livre, mas para testes mais realistas use JSON:

```json
{
  "order_id": "ORD-1001",
  "customer_name": "Maria Souza",
  "customer_email": "maria@cliente.com",
  "total_amount": 799.90,
  "items_count": 3
}
```
