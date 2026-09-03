import { SQSClient, SendMessageCommand } from "@aws-sdk/client-sqs";

const region = process.env.AWS_REGION || "us-east-1";
const outputQueueUrl = process.env.SQS_OUTPUT_QUEUE_URL;

const sqsClient = new SQSClient({ region });

/**
 * Caso de uso didático: processamento de pedido.
 * - Aplica regra simples de prioridade de atendimento.
 * - Publica resultado na fila de saída para a EC2 consultar no front.
 */
export const handler = async (event) => {
  const payload = event?.order ? event : normalizeFromSqs(event);

  if (!outputQueueUrl) {
    throw new Error("Defina SQS_OUTPUT_QUEUE_URL nas variáveis da Lambda.");
  }

  const priority = payload.order.total_amount >= 500 ? "HIGH" : "NORMAL";

  const result = {
    event_type: "ORDER_PROCESSED",
    processed_at: new Date().toISOString(),
    order_id: payload.order.order_id,
    customer_email: payload.order.customer_email,
    priority,
    estimated_ship_days: priority === "HIGH" ? 1 : 3,
  };

  await sqsClient.send(
    new SendMessageCommand({
      QueueUrl: outputQueueUrl,
      MessageBody: JSON.stringify(result),
    })
  );

  return {
    ok: true,
    message: "Pedido processado e publicado na fila de saída.",
    data: result,
  };
};

function normalizeFromSqs(event) {
  const firstRecord = event?.Records?.[0];
  const body = firstRecord?.body ? JSON.parse(firstRecord.body) : {};

  return {
    source: "sqs",
    order: body.order || {
      order_id: `ORD-${Date.now()}`,
      customer_email: "sem-email@exemplo.com",
      total_amount: 0,
    },
  };
}
