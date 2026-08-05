<?php

require __DIR__ . '/../src/config.php';

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function payload(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $decoded = $raw ? json_decode($raw, true) : [];
        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

function normalizeCompany(array $company): array
{
    return [
        'id' => (int) $company['id'],
        'nome_da_empresa' => $company['nome_da_empresa'],
        'cod_cvm' => $company['cod_cvm'] === null ? null : (int) $company['cod_cvm'],
    ];
}

function validateCompany(array $data): array
{
    $nome = trim($data['nome_da_empresa'] ?? '');
    $codCvm = trim((string) ($data['cod_cvm'] ?? ''));

    if ($nome === '') {
        throw new InvalidArgumentException('Informe o nome da empresa.');
    }

    return [$nome, $codCvm === '' ? null : (int) $codCvm];
}

if (($_GET['api'] ?? '') === 'empresas') {
    try {
        $pdo = db();
        ensureEmpresasTable($pdo);
        $method = $_SERVER['REQUEST_METHOD'];

        if ($method === 'GET') {
            $search = trim($_GET['q'] ?? '');
            $params = [];
            $where = '';

            if ($search !== '') {
                $where = 'WHERE nome_da_empresa LIKE :search OR cod_cvm = :cod_cvm';
                $params['search'] = $search . '%';
                $params['cod_cvm'] = ctype_digit($search) ? (int) $search : -1;
            }

            $stmt = $pdo->prepare("SELECT id, nome_da_empresa, cod_cvm FROM empresas {$where} ORDER BY id DESC LIMIT 100");
            $stmt->execute($params);

            jsonResponse([
                'empresas' => array_map('normalizeCompany', $stmt->fetchAll()),
            ]);
        }

        $data = payload();

        if ($method === 'POST') {
            [$nome, $codCvm] = validateCompany($data);
            $stmt = $pdo->prepare('INSERT INTO empresas (nome_da_empresa, cod_cvm) VALUES (:nome_da_empresa, :cod_cvm)');
            $stmt->execute(['nome_da_empresa' => $nome, 'cod_cvm' => $codCvm]);
            jsonResponse(['message' => 'Empresa cadastrada.', 'id' => (int) $pdo->lastInsertId()], 201);
        }

        if ($method === 'PUT') {
            [$nome, $codCvm] = validateCompany($data);
            $stmt = $pdo->prepare('UPDATE empresas SET nome_da_empresa = :nome_da_empresa, cod_cvm = :cod_cvm WHERE id = :id');
            $stmt->execute(['nome_da_empresa' => $nome, 'cod_cvm' => $codCvm, 'id' => (int) ($data['id'] ?? 0)]);
            jsonResponse(['message' => 'Empresa atualizada.']);
        }

        if ($method === 'DELETE') {
            $stmt = $pdo->prepare('DELETE FROM empresas WHERE id = :id');
            $stmt->execute(['id' => (int) ($data['id'] ?? 0)]);
            jsonResponse(['message' => 'Empresa excluída.']);
        }

        jsonResponse(['error' => 'Método não permitido.'], 405);
    } catch (InvalidArgumentException $exception) {
        jsonResponse(['error' => $exception->getMessage()], 422);
    } catch (Throwable $exception) {
        jsonResponse(['error' => 'Não foi possível conectar ou preparar o banco de dados. Confira o arquivo .env e as permissões do usuário no MySQL/MariaDB.'], 503);
    }
}
?>
<!doctype html>
<html lang="pt-BR" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cadastro de Empresas</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' };</script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
    <main class="mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 py-10 sm:px-6 lg:px-8">
        <section class="rounded-3xl border border-slate-800 bg-slate-900/80 p-6 shadow-2xl shadow-slate-950/40">
            <div class="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.3em] text-cyan-400">Empresas</p>
                    <h1 class="mt-2 text-3xl font-bold tracking-tight text-white">Cadastro de empresas</h1>
                    <p class="mt-2 text-slate-400">Renderização incremental no navegador, API JSON enxuta e busca limitada/indexada no banco.</p>
                </div>
                <label class="block md:w-80">
                    <span class="mb-2 block text-sm font-medium text-slate-300">Busca rápida</span>
                    <input id="search" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white outline-none ring-cyan-500 transition placeholder:text-slate-500 focus:border-cyan-400 focus:ring-2" placeholder="Nome ou código CVM">
                </label>
            </div>

            <div id="notice" class="mb-5 hidden rounded-2xl border px-4 py-3 text-sm"></div>

            <form id="company-form" class="grid gap-4 rounded-2xl border border-slate-800 bg-slate-950/70 p-5 md:grid-cols-[1fr_220px_auto] md:items-end">
                <input id="company-id" type="hidden">
                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-slate-300">Nome da empresa</span>
                    <input id="nome-da-empresa" required class="w-full rounded-xl border border-slate-700 bg-slate-900 px-4 py-3 text-white outline-none ring-cyan-500 transition placeholder:text-slate-500 focus:border-cyan-400 focus:ring-2" placeholder="Ex.: Empresa S.A.">
                </label>
                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-slate-300">Código CVM</span>
                    <input id="cod-cvm" type="number" class="w-full rounded-xl border border-slate-700 bg-slate-900 px-4 py-3 text-white outline-none ring-cyan-500 transition placeholder:text-slate-500 focus:border-cyan-400 focus:ring-2" placeholder="Opcional">
                </label>
                <div class="flex gap-3">
                    <button id="submit-button" class="rounded-xl bg-cyan-500 px-5 py-3 font-semibold text-slate-950 transition hover:bg-cyan-400">Cadastrar</button>
                    <button id="cancel-button" type="button" class="hidden rounded-xl border border-slate-700 px-5 py-3 font-semibold text-slate-200 transition hover:bg-slate-800">Cancelar</button>
                </div>
            </form>
        </section>

        <section class="overflow-hidden rounded-3xl border border-slate-800 bg-slate-900/80 shadow-2xl shadow-slate-950/40">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-800">
                    <thead class="bg-slate-950/80 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                        <tr>
                            <th class="px-6 py-4">ID</th>
                            <th class="px-6 py-4">Nome da empresa</th>
                            <th class="px-6 py-4">Código CVM</th>
                            <th class="px-6 py-4 text-right">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="companies-body" class="divide-y divide-slate-800 text-sm">
                        <tr><td colspan="4" class="px-6 py-10 text-center text-slate-400">Carregando...</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <script>
        const apiUrl = '<?= e($_SERVER['SCRIPT_NAME'] ?? '/index.php') ?>?api=empresas';
        const state = { empresas: [], q: '', timer: null };
        const form = document.getElementById('company-form');
        const notice = document.getElementById('notice');
        const tbody = document.getElementById('companies-body');
        const idInput = document.getElementById('company-id');
        const nomeInput = document.getElementById('nome-da-empresa');
        const codCvmInput = document.getElementById('cod-cvm');
        const submitButton = document.getElementById('submit-button');
        const cancelButton = document.getElementById('cancel-button');
        const searchInput = document.getElementById('search');

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[char]));
        }

        function showNotice(message, type = 'success') {
            notice.textContent = message;
            notice.className = `mb-5 rounded-2xl border px-4 py-3 text-sm ${type === 'error' ? 'border-red-500/40 bg-red-950/60 text-red-100' : 'border-emerald-500/40 bg-emerald-950/60 text-emerald-100'}`;
        }

        function resetForm() {
            idInput.value = '';
            form.reset();
            submitButton.textContent = 'Cadastrar';
            cancelButton.classList.add('hidden');
        }

        function render() {
            if (!state.empresas.length) {
                tbody.innerHTML = '<tr><td colspan="4" class="px-6 py-10 text-center text-slate-400">Nenhuma empresa encontrada.</td></tr>';
                return;
            }

            tbody.innerHTML = state.empresas.map(company => `
                <tr class="hover:bg-slate-800/50">
                    <td class="px-6 py-4 text-slate-400">${company.id}</td>
                    <td class="px-6 py-4 font-medium text-white">${escapeHtml(company.nome_da_empresa)}</td>
                    <td class="px-6 py-4 text-slate-300">${company.cod_cvm ?? '—'}</td>
                    <td class="px-6 py-4">
                        <div class="flex justify-end gap-3">
                            <button data-edit="${company.id}" class="rounded-lg border border-cyan-500/40 px-3 py-2 text-cyan-300 transition hover:bg-cyan-500/10">Editar</button>
                            <button data-delete="${company.id}" class="rounded-lg border border-red-500/40 px-3 py-2 text-red-300 transition hover:bg-red-500/10">Excluir</button>
                        </div>
                    </td>
                </tr>`).join('');
        }

        async function request(method = 'GET', body = null) {
            const response = await fetch(`${apiUrl}&q=${encodeURIComponent(state.q)}`, {
                method,
                headers: { 'Content-Type': 'application/json' },
                body: body ? JSON.stringify(body) : null,
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Erro inesperado.');
            return data;
        }

        async function loadCompanies() {
            const data = await request();
            state.empresas = data.empresas;
            render();
        }

        form.addEventListener('submit', async event => {
            event.preventDefault();
            try {
                const id = idInput.value;
                const body = { id, nome_da_empresa: nomeInput.value, cod_cvm: codCvmInput.value };
                const data = await request(id ? 'PUT' : 'POST', body);
                showNotice(data.message);
                resetForm();
                await loadCompanies();
            } catch (error) {
                showNotice(error.message, 'error');
            }
        });

        tbody.addEventListener('click', async event => {
            const editId = event.target.dataset.edit;
            const deleteId = event.target.dataset.delete;

            if (editId) {
                const company = state.empresas.find(item => item.id === Number(editId));
                if (!company) return;
                idInput.value = company.id;
                nomeInput.value = company.nome_da_empresa;
                codCvmInput.value = company.cod_cvm ?? '';
                submitButton.textContent = 'Salvar';
                cancelButton.classList.remove('hidden');
                nomeInput.focus();
            }

            if (deleteId && confirm('Excluir esta empresa?')) {
                try {
                    const data = await request('DELETE', { id: deleteId });
                    showNotice(data.message);
                    resetForm();
                    await loadCompanies();
                } catch (error) {
                    showNotice(error.message, 'error');
                }
            }
        });

        cancelButton.addEventListener('click', resetForm);
        searchInput.addEventListener('input', () => {
            clearTimeout(state.timer);
            state.timer = setTimeout(async () => {
                state.q = searchInput.value;
                try { await loadCompanies(); } catch (error) { showNotice(error.message, 'error'); }
            }, 180);
        });

        loadCompanies().catch(error => showNotice(error.message, 'error'));
    </script>
</body>
</html>
