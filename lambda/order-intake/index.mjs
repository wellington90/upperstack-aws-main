import { LambdaClient, InvokeCommand } from "@aws-sdk/client-lambda";
import { SQSClient, SendMessageCommand } from "@aws-sdk/client-sqs";

const region = process.env.AWS_REGION || "us-east-1";
const nextLambdaArn = process.env.ORDER_PROCESSOR_ARN;
const inputQueueUrl = process.env.SQS_INPUT_QUEUE_URL;

const lambdaClient = new LambdaClient({ region });
const sqsClient = new SQSClient({ region });

/**
 * Caso de uso didático: confirmação de pedido de e-commerce.
 * 1) Recebe payload do pedido pelo API Gateway/EC2.
 * 2) Publica evento na fila de entrada para rastreabilidade.
 * 3) Dispara lambda de processamento assíncrono.
 */
export const handler = async (event) => {
  const payload = normalizePayload(event);

  if (!inputQueueUrl || !nextLambdaArn) {
    throw new Error("Defina ORDER_PROCESSOR_ARN e SQS_INPUT_QUEUE_URL nas variáveis da Lambda.");
  }

  await sqsClient.send(
    new SendMessageCommand({
      QueueUrl: inputQueueUrl,
      MessageBody: JSON.stringify({
        event_type: "ORDER_RECEIVED",
        sent_at: new Date().toISOString(),
        order: payload,
      }),
    })
  );

  await lambdaClient.send(
    new InvokeCommand({
      FunctionName: nextLambdaArn,
      InvocationType: "Event",
      Payload: Buffer.from(
        JSON.stringify({
          source: "order-intake",
          order: payload,
        })
      ),
    })
  );

  return {
    ok: true,
    message: "Pedido recebido e encaminhado para processamento.",
    order_id: payload.order_id,
  };
};

function normalizePayload(event) {
  const body = event?.body ? JSON.parse(event.body) : event;

  return {
    order_id: body?.order_id || `ORD-${Date.now()}`,
    customer_name: body?.customer_name || "Cliente sem nome",
    customer_email: body?.customer_email || "sem-email@exemplo.com",
    total_amount: Number(body?.total_amount || 0),
    items_count: Number(body?.items_count || 0),
  };
}
