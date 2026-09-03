<?php

declare(strict_types=1);
?><!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Upperstack AWS Demo</title>
  <style>
    :root {
      --bg: #f4f4f5;
      --panel: #ffffff;
      --primary: #0b1220;
      --accent: #f97339;
      --accent-dark: #ea5b1f;
      --accent-soft: #fff1ea;
      --muted: #4b5563;
      --border: #e5e7eb;
      --success: #0f766e;
      --danger: #b91c1c;
    }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: Inter, Arial, sans-serif; color: var(--primary); background: var(--bg); }
    .layout { display: grid; grid-template-columns: 280px 1fr; min-height: 100vh; }
    .sidebar { background: linear-gradient(180deg, #000000 0%, #111111 100%); color: #fff; padding: 24px 18px; }
    .sidebar h1 { margin: 0 0 8px; font-size: 1.05rem; }
    .sidebar p { margin: 0 0 18px; color: #cbd5e1; font-size: .92rem; }
    .brand-logo {
      width: 100%;
      max-width: 180px;
      max-height: 68px;
      object-fit: contain;
      display: block;
      margin: 0 0 14px;
      background: #fff;
      border-radius: 8px;
      padding: 8px;
    }
    .brand-fallback {
      font-size: 1.25rem;
      font-weight: 700;
      letter-spacing: .3px;
      margin-bottom: 12px;
    }
    .menu { display: grid; gap: 8px; }
    .menu button {
      width: 100%; text-align: left; border: 1px solid transparent; background: #0f0f0f; color: #e2e8f0;
      border-radius: 8px; padding: 10px 12px; cursor: pointer;
    }
    .menu button:hover, .menu button.active { border-color: #f97339; background: #1a1a1a; color: #fff5f0; }

    .content { padding: 22px; }
    .hero { background: linear-gradient(120deg, #ffffff 0%, #fff6f2 100%); border: 1px solid #ffd8c8; border-radius: 12px; padding: 16px 18px; margin-bottom: 16px; }
    .hero h2 { margin: 0 0 8px; }
    .hero p { margin: 0; color: var(--muted); }

    .section { display: none; background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 16px; }
    .section.active { display: block; }
    .section h3 { margin-top: 0; }

    .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    label { font-size: .85rem; color: #334155; display: block; margin: 8px 0 4px; }
    input, textarea {
      width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 9px 10px; font-size: .95rem;
      background: #fff;
    }
    textarea { min-height: 100px; }
    .actions { margin-top: 12px; display: flex; gap: 8px; flex-wrap: wrap; }
    button.primary, button.secondary, button.danger {
      border: 0; border-radius: 8px; padding: 9px 12px; cursor: pointer; font-weight: 600;
    }
    button.primary { background: var(--accent); color: #fff; }
    button.primary:hover { background: var(--accent-dark); }
    button.secondary { background: #e5e7eb; color: #111827; }
    button.danger { background: #fff1ea; color: #9a3412; border: 1px solid #fdba97; }

    pre {
      background: #000000; color: #e2e8f0; padding: 12px; border-radius: 10px; margin-top: 12px;
      min-height: 120px; overflow: auto;
    }

    table { width: 100%; border-collapse: collapse; margin-top: 14px; }
    th, td { border-bottom: 1px solid var(--border); padding: 8px 6px; text-align: left; font-size: .92rem; }
    th { background: #f9fafb; }
    .badge { display: inline-block; font-size: .75rem; padding: 3px 8px; border-radius: 999px; background: var(--accent-soft); color: #c2410c; border: 1px solid #fdba97; }

    @media (max-width: 960px) {
      .layout { grid-template-columns: 1fr; }
      .row { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
<div class="layout">
  <aside class="sidebar">
    <img id="brand-logo" class="brand-logo" alt="Logo Upperstack" style="display:none;">
    <div id="brand-fallback" class="brand-fallback">Upperstack</div>
    <h1>Upperstack AWS Demo</h1>
    <p>Menu lateral para aulas práticas de integração EC2 + RDS + S3 + Lambda + SQS V2.</p>

    <nav class="menu">
      <button class="active" data-target="sec-home">Início (EC2 Metadata)</button>
      <button data-target="sec-rds">RDS + CRUD de Usuários</button>
      <button data-target="sec-config">Configuração da App</button>
      <button data-target="sec-secret">AWS Secrets Manager</button>
      <button data-target="sec-s3">Upload para S3</button>
      <button data-target="sec-lambda">Lambda + SQS</button>
      <button data-target="sec-processed">Fila Processada</button>
    </nav>
  </aside>

  <main class="content">
    <section class="hero">
      <h2>Painel didático de integrações AWS - V4</h2>
      <p>Fluxo recomendado: valide conexão RDS, faça CRUD de usuários, depois teste S3 e pipeline Lambda/SQS.</p>
    </section>

    <section id="sec-home" class="section active">
      <h3>Início: metadados da EC2 + teste de Auto Scaling</h3>
      <p><span class="badge">infra</span> Use este painel para provar alternância de instâncias atrás do Load Balancer.</p>
      <div class="row">
        <div>
          <label for="stress-seconds">Duração do estresse (segundos, 0 = contínuo até Stop)</label>
          <input id="stress-seconds" type="number" min="0" max="3600" value="0">
        </div>
        <div>
          <label for="stress-workers">Workers de CPU</label>
          <input id="stress-workers" type="number" min="1" max="8" value="2">
        </div>
      </div>
      <div class="actions">
        <button class="primary" onclick="loadMetadata()">Atualizar metadados</button>
        <button class="danger" onclick="startAutoscalingTest()">Start Auto Scaling (CPU 100%)</button>
        <button class="secondary" onclick="stopAutoscalingTest()">Stop Auto Scaling (kill processo)</button>
      </div>
      <pre id="out-metadata"></pre>
    </section>

    <section id="sec-rds" class="section">
      <h3>RDS + Cadastro de Usuários</h3>
      <p><span class="badge">didática real</span> Cenário: cadastro básico de clientes para uma campanha.</p>
      <div class="actions">
        <button class="primary" onclick="connectRds('rds')">Conectar RDS (.env)</button>
        <button class="secondary" onclick="connectRds('rds-secret')">Conectar RDS (Secrets Manager)</button>
        <button class="secondary" onclick="loadUsers()">Atualizar lista</button>
      </div>
      <pre id="out-rds"></pre>

      <div class="row">
        <div>
          <label for="user-id">ID (para editar/excluir)</label>
          <input id="user-id" type="number" placeholder="Ex: 1">
        </div>
        <div>
          <label for="user-email">E-mail</label>
          <input id="user-email" type="email" placeholder="nome@empresa.com">
        </div>
      </div>

      <label for="user-name">Nome</label>
      <input id="user-name" type="text" placeholder="Nome completo">

      <div class="actions">
        <button class="primary" onclick="createUser()">Incluir</button>
        <button class="secondary" onclick="updateUser()">Editar</button>
        <button class="danger" onclick="deleteUser()">Excluir</button>
      </div>

      <pre id="out-users"></pre>
      <table id="users-table">
        <thead>
        <tr>
          <th>ID</th><th>Nome</th><th>E-mail</th><th>Criado em</th>
        </tr>
        </thead>
        <tbody></tbody>
      </table>
    </section>

    <section id="sec-config" class="section">
      <h3>Configuração (.env + ambiente EC2)</h3>
      <button class="primary" onclick="callApi('config', 'out-config')">Ler configurações</button>
      <pre id="out-config"></pre>
    </section>

    <section id="sec-secret" class="section">
      <h3>Secrets Manager</h3>
      <p><span class="badge">boa prática</span> Demonstre RDS sem credenciais no <code>.env</code>, usando apenas o secret.</p>
      <div class="actions">
        <button class="primary" onclick="callApi('secret', 'out-secret')">Buscar secret</button>
        <button class="secondary" onclick="callApi('rds-secret', 'out-secret')">Conectar RDS via secret</button>
      </div>
      <pre id="out-secret"></pre>
    </section>

    <section id="sec-s3" class="section">
      <h3>Upload para S3</h3>
      <p>Imagem enviada aqui será usada como logo do site <strong>Upperstack</strong>.</p>
      <form id="upload-form">
        <input type="file" name="file" accept="image/*" required>
        <div class="actions"><button class="primary" type="submit">Enviar imagem de logo</button></div>
      </form>
      <pre id="out-s3"></pre>
    </section>

    <section id="sec-lambda" class="section">
      <h3>Acionar Lambda + enviar para SQS</h3>
      <label for="message">Mensagem de negócio</label>
      <textarea id="message" placeholder='Pode ser texto livre ou JSON. Ex: {"order_id":"ORD-1001","customer_name":"Maria","customer_email":"maria@cliente.com","total_amount":799.9,"items_count":3}'></textarea>
      <div class="actions"><button class="primary" onclick="sendMessage()">Enviar mensagem</button></div>
      <pre id="out-send"></pre>
    </section>

    <section id="sec-processed" class="section">
      <h3>Consultar fila processada</h3>
      <button class="primary" onclick="callApi('poll-processed', 'out-processed')">Consultar fila</button>
      <pre id="out-processed"></pre>
    </section>
  </main>
</div>

<script>
const menuButtons = Array.from(document.querySelectorAll('.menu button'));
const sections = Array.from(document.querySelectorAll('.section'));
let currentRdsSource = 'env';

menuButtons.forEach((button) => {
  button.addEventListener('click', () => {
    menuButtons.forEach((b) => b.classList.remove('active'));
    button.classList.add('active');

    sections.forEach((section) => section.classList.remove('active'));
    document.getElementById(button.dataset.target).classList.add('active');
  });
});

async function callApi(action, outputId) {
  const out = document.getElementById(outputId);
  out.textContent = 'Carregando...';

  const response = await fetch(`/api.php?action=${action}`);
  const data = await response.json();
  out.textContent = JSON.stringify(data, null, 2);
}

async function connectRds(action) {
  const out = document.getElementById('out-rds');
  out.textContent = 'Conectando no RDS...';

  const response = await fetch(`/api.php?action=${action}`);
  const data = await response.json();
  out.textContent = JSON.stringify(data, null, 2);

  if (data?.ok) {
    currentRdsSource = action === 'rds-secret' ? 'secret' : 'env';
    await loadUsers();
  }
}

async function loadLogo() {
  const response = await fetch('/api.php?action=logo');
  const data = await response.json();

  const logo = document.getElementById('brand-logo');
  const fallback = document.getElementById('brand-fallback');
  const logoUrl = data?.data?.logo_url;

  if (logoUrl) {
    logo.src = logoUrl;
    logo.style.display = 'block';
    fallback.style.display = 'none';
    return;
  }

  logo.style.display = 'none';
  fallback.style.display = 'block';
}

async function loadUsers() {
  const out = document.getElementById('out-users');
  out.textContent = 'Carregando usuários...';

  const response = await fetch(`/api.php?action=users-list&source=${encodeURIComponent(currentRdsSource)}`);
  const data = await response.json();
  out.textContent = JSON.stringify(data, null, 2);

  const tbody = document.querySelector('#users-table tbody');
  tbody.innerHTML = '';

  const users = data?.data?.users || [];
  users.forEach((user) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${user.id ?? ''}</td><td>${user.name ?? ''}</td><td>${user.email ?? ''}</td><td>${user.created_at ?? ''}</td>`;
    tr.addEventListener('click', () => {
      document.getElementById('user-id').value = user.id || '';
      document.getElementById('user-name').value = user.name || '';
      document.getElementById('user-email').value = user.email || '';
    });
    tbody.appendChild(tr);
  });
}

async function loadMetadata() {
  const out = document.getElementById('out-metadata');
  out.textContent = 'Carregando metadados da instância...';

  const response = await fetch('/api.php?action=metadata');
  const data = await response.json();
  out.textContent = JSON.stringify(data, null, 2);
}

async function startAutoscalingTest() {
  const out = document.getElementById('out-metadata');
  out.textContent = 'Iniciando teste de estresse...';

  const seconds = Number(document.getElementById('stress-seconds').value || 300);
  const workers = Number(document.getElementById('stress-workers').value || 2);
  const response = await fetch('/api.php?action=autoscaling-test', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ seconds, workers }),
  });

  const data = await response.json();
  out.textContent = JSON.stringify(data, null, 2);
}


async function stopAutoscalingTest() {
  const out = document.getElementById('out-metadata');
  out.textContent = 'Parando teste de estresse...';

  const response = await fetch('/api.php?action=autoscaling-stop', {
    method: 'POST',
  });

  const data = await response.json();
  out.textContent = JSON.stringify(data, null, 2);
}

async function createUser() {
  await usersMutation('users-create', 'POST', {
    name: document.getElementById('user-name').value,
    email: document.getElementById('user-email').value,
  });
}

async function updateUser() {
  await usersMutation('users-update', 'PUT', {
    id: Number(document.getElementById('user-id').value),
    name: document.getElementById('user-name').value,
    email: document.getElementById('user-email').value,
  });
}

async function deleteUser() {
  await usersMutation('users-delete', 'DELETE', {
    id: Number(document.getElementById('user-id').value),
  });
}

async function usersMutation(action, method, payload) {
  const out = document.getElementById('out-users');
  out.textContent = 'Processando...';

  const response = await fetch(`/api.php?action=${action}`, {
    method,
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      ...payload,
      source: currentRdsSource,
    }),
  });

  out.textContent = JSON.stringify(await response.json(), null, 2);
  await loadUsers();
}

document.getElementById('upload-form').addEventListener('submit', async (event) => {
  event.preventDefault();
  const formData = new FormData(event.target);
  document.getElementById('out-s3').textContent = 'Enviando...';

  const response = await fetch('/api.php?action=logo-upload', {
    method: 'POST',
    body: formData,
  });

  document.getElementById('out-s3').textContent = JSON.stringify(await response.json(), null, 2);
  await loadLogo();
});

async function sendMessage() {
  document.getElementById('out-send').textContent = 'Enviando...';

  const response = await fetch('/api.php?action=send-message', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ message: document.getElementById('message').value || 'Pedido sem mensagem personalizada.' }),
  });

  document.getElementById('out-send').textContent = JSON.stringify(await response.json(), null, 2);
}

loadUsers();
loadLogo();
loadMetadata();
</script>
</body>
</html>
