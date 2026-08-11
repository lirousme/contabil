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
    $tickers = [];

    if (!empty($company['tickers_json'])) {
        $decoded = json_decode($company['tickers_json'], true);
        $tickers = is_array($decoded) ? array_values(array_filter($decoded)) : [];
    }

    return [
        'id' => (int) $company['id'],
        'nome_da_empresa' => $company['nome_da_empresa'],
        'tickers' => $tickers,
        'cod_cvm' => $company['cod_cvm'] === null ? null : (string) $company['cod_cvm'],
        'id_setor' => $company['id_setor'] === null ? null : (int) $company['id_setor'],
        'setor' => $company['setor'] ?? null,
    ];
}

function validateCompany(array $data): array
{
    $nome = trim($data['nome_da_empresa'] ?? '');
    $codCvm = trim((string) ($data['cod_cvm'] ?? ''));
    $setorId = filter_var($data['id_setor'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($nome === '') {
        throw new InvalidArgumentException('Informe o nome da empresa.');
    }

    if ($codCvm !== '' && !ctype_digit($codCvm)) {
        throw new InvalidArgumentException('Informe apenas números no código CVM.');
    }

    if (!$setorId) {
        throw new InvalidArgumentException('Escolha o setor da empresa.');
    }

    return [$nome, $codCvm === '' ? null : $codCvm, $setorId];
}

function validateTickers(array $data): array
{
    $allowedExchanges = ['BVMF', 'BME', 'NYSE'];
    $tickers = [];

    foreach (($data['tickers'] ?? []) as $tickerData) {
        $bolsa = strtoupper(trim((string) ($tickerData['bolsa'] ?? '')));
        $ticker = strtoupper(trim((string) ($tickerData['ticker'] ?? '')));

        if ($bolsa === '' && $ticker === '') {
            continue;
        }

        if (!in_array($bolsa, $allowedExchanges, true)) {
            throw new InvalidArgumentException('Escolha uma bolsa válida para o ticker.');
        }

        if ($ticker === '') {
            throw new InvalidArgumentException('Informe o ticker.');
        }

        $tickers[] = ['bolsa' => $bolsa, 'ticker' => $ticker];
    }

    return $tickers;
}

function validateSetor(PDO $pdo, int $setorId): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM setores WHERE id = :id');
    $stmt->execute(['id' => $setorId]);

    if ((int) $stmt->fetchColumn() === 0) {
        throw new InvalidArgumentException('Escolha um setor válido.');
    }
}

function replaceTickers(PDO $pdo, int $companyId, array $tickers): void
{
    $delete = $pdo->prepare('DELETE FROM tickers WHERE id_empresa = :id_empresa');
    $delete->execute(['id_empresa' => $companyId]);

    $insert = $pdo->prepare('INSERT INTO tickers (id_empresa, bolsa, ticker) VALUES (:id_empresa, :bolsa, :ticker)');

    foreach ($tickers as $ticker) {
        $insert->execute(['id_empresa' => $companyId, 'bolsa' => $ticker['bolsa'], 'ticker' => $ticker['ticker']]);
    }
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
                $where = 'WHERE nome_da_empresa LIKE :search OR cod_cvm LIKE :cod_cvm';
                $params['search'] = $search . '%';
                $params['cod_cvm'] = $search . '%';
            }

            $stmt = $pdo->prepare("SELECT empresas.id, empresas.nome_da_empresa, empresas.cod_cvm, empresas.id_setor, setores.nome AS setor,
                COALESCE(
                    JSON_ARRAYAGG(
                        CASE WHEN tickers.id IS NULL THEN NULL ELSE JSON_OBJECT('id', tickers.id, 'bolsa', tickers.bolsa, 'ticker', tickers.ticker) END
                    ),
                    JSON_ARRAY()
                ) AS tickers_json
                FROM empresas
                LEFT JOIN setores ON setores.id = empresas.id_setor
                LEFT JOIN tickers ON tickers.id_empresa = empresas.id
                {$where}
                GROUP BY empresas.id, empresas.nome_da_empresa, empresas.cod_cvm, empresas.id_setor, setores.nome
                ORDER BY empresas.id DESC
                LIMIT 100");
            $stmt->execute($params);

            $setoresStmt = $pdo->query('SELECT id, nome FROM setores ORDER BY nome');

            jsonResponse([
                'empresas' => array_map('normalizeCompany', $stmt->fetchAll()),
                'setores' => $setoresStmt->fetchAll(),
            ]);
        }

        $data = payload();

        if ($method === 'POST') {
            [$nome, $codCvm, $setorId] = validateCompany($data);
            $tickers = validateTickers($data);
            validateSetor($pdo, $setorId);
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO empresas (nome_da_empresa, cod_cvm, id_setor) VALUES (:nome_da_empresa, :cod_cvm, :id_setor)');
            $stmt->execute(['nome_da_empresa' => $nome, 'cod_cvm' => $codCvm, 'id_setor' => $setorId]);
            $companyId = (int) $pdo->lastInsertId();
            replaceTickers($pdo, $companyId, $tickers);
            $pdo->commit();
            jsonResponse(['message' => 'Empresa cadastrada.', 'id' => $companyId], 201);
        }

        if ($method === 'PUT') {
            [$nome, $codCvm, $setorId] = validateCompany($data);
            $tickers = validateTickers($data);
            validateSetor($pdo, $setorId);
            $companyId = (int) ($data['id'] ?? 0);
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('UPDATE empresas SET nome_da_empresa = :nome_da_empresa, cod_cvm = :cod_cvm, id_setor = :id_setor WHERE id = :id');
            $stmt->execute(['nome_da_empresa' => $nome, 'cod_cvm' => $codCvm, 'id_setor' => $setorId, 'id' => $companyId]);
            replaceTickers($pdo, $companyId, $tickers);
            $pdo->commit();
            jsonResponse(['message' => 'Empresa atualizada.']);
        }

        if ($method === 'DELETE') {
            $stmt = $pdo->prepare('DELETE FROM empresas WHERE id = :id');
            $stmt->execute(['id' => (int) ($data['id'] ?? 0)]);
            jsonResponse(['message' => 'Empresa excluída.']);
        }

        jsonResponse(['error' => 'Método não permitido.'], 405);
    } catch (InvalidArgumentException $exception) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        jsonResponse(['error' => $exception->getMessage()], 422);
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

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

            <button id="toggle-company-form" type="button" class="mb-4 flex w-full items-center justify-between rounded-2xl border border-slate-800 bg-slate-950/70 px-5 py-4 text-left font-semibold text-white transition hover:border-cyan-500/60" aria-expanded="false" aria-controls="company-form-panel"><span id="company-form-title">Cadastrar empresa</span><span id="company-form-icon" class="text-cyan-300">+</span></button>
            <div id="company-form-panel" class="hidden">
            <form id="company-form" class="grid gap-4 rounded-2xl border border-slate-800 bg-slate-950/70 p-5 md:grid-cols-[1fr_220px_220px_auto] md:items-end">
                <input id="company-id" type="hidden">
                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-slate-300">Nome da empresa</span>
                    <input id="nome-da-empresa" required class="w-full rounded-xl border border-slate-700 bg-slate-900 px-4 py-3 text-white outline-none ring-cyan-500 transition placeholder:text-slate-500 focus:border-cyan-400 focus:ring-2" placeholder="Ex.: Empresa S.A.">
                </label>
                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-slate-300">Código CVM</span>
                    <input id="cod-cvm" inputmode="numeric" pattern="[0-9]*" class="w-full rounded-xl border border-slate-700 bg-slate-900 px-4 py-3 text-white outline-none ring-cyan-500 transition placeholder:text-slate-500 focus:border-cyan-400 focus:ring-2" placeholder="Opcional">
                </label>
                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-slate-300">Setor</span>
                    <select id="id-setor" required class="w-full rounded-xl border border-slate-700 bg-slate-900 px-4 py-3 text-white outline-none ring-cyan-500 transition focus:border-cyan-400 focus:ring-2"></select>
                </label>
                <div class="flex gap-3">
                    <button id="submit-button" class="rounded-xl bg-cyan-500 px-5 py-3 font-semibold text-slate-950 transition hover:bg-cyan-400">Cadastrar</button>
                    <button id="cancel-button" type="button" class="hidden rounded-xl border border-slate-700 px-5 py-3 font-semibold text-slate-200 transition hover:bg-slate-800">Cancelar</button>
                </div>
                <fieldset class="md:col-span-4 rounded-2xl border border-slate-800 p-4">
                    <legend class="px-2 text-sm font-medium text-slate-300">Tickers</legend>
                    <div class="grid gap-3 md:grid-cols-[160px_1fr_auto] md:items-end">
                        <label class="block">
                            <span class="mb-2 block text-sm font-medium text-slate-300">Bolsa</span>
                            <select id="ticker-bolsa" class="w-full rounded-xl border border-slate-700 bg-slate-900 px-4 py-3 text-white outline-none ring-cyan-500 transition focus:border-cyan-400 focus:ring-2">
                                <option value="BVMF">BVMF</option>
                                <option value="BME">BME</option>
                                <option value="NYSE">NYSE</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="mb-2 block text-sm font-medium text-slate-300">Ticker</span>
                            <input id="ticker-input" class="w-full rounded-xl border border-slate-700 bg-slate-900 px-4 py-3 uppercase text-white outline-none ring-cyan-500 transition placeholder:text-slate-500 focus:border-cyan-400 focus:ring-2" placeholder="Ex.: PETR4">
                        </label>
                        <button id="add-ticker" type="button" class="rounded-xl border border-cyan-500/40 px-5 py-3 font-semibold text-cyan-300 transition hover:bg-cyan-500/10">Adicionar ticker</button>
                    </div>
                    <div id="tickers-list" class="mt-3 flex flex-wrap gap-2 text-sm text-slate-300"></div>
                </fieldset>
            </form>
            </div>
        </section>

        <section class="overflow-hidden rounded-3xl border border-slate-800 bg-slate-900/80 shadow-2xl shadow-slate-950/40">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-800">
                    <thead class="bg-slate-950/80 text-left text-xs font-semibold uppercase tracking-wider text-slate-400">
                        <tr>
                            <th class="px-6 py-4">Nome da empresa</th>
                            <th class="px-6 py-4">Tickers BVMF</th>
                            <th class="px-6 py-4">Setor</th>
                            <th class="px-6 py-4">Código CVM</th>
                            <th class="px-6 py-4 text-right">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="companies-body" class="divide-y divide-slate-800 text-sm">
                        <tr><td colspan="5" class="px-6 py-10 text-center text-slate-400">Carregando...</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <script>
        const apiUrl = '<?= e($_SERVER['SCRIPT_NAME'] ?? '/index.php') ?>?api=empresas';
        const state = { empresas: [], setores: [], q: '', timer: null, tickers: [] };
        const form = document.getElementById('company-form');
        const notice = document.getElementById('notice');
        const tbody = document.getElementById('companies-body');
        const idInput = document.getElementById('company-id');
        const nomeInput = document.getElementById('nome-da-empresa');
        const codCvmInput = document.getElementById('cod-cvm');
        const setorInput = document.getElementById('id-setor');
        const formPanel = document.getElementById('company-form-panel');
        const toggleCompanyForm = document.getElementById('toggle-company-form');
        const companyFormIcon = document.getElementById('company-form-icon');
        const companyFormTitle = document.getElementById('company-form-title');
        const submitButton = document.getElementById('submit-button');
        const cancelButton = document.getElementById('cancel-button');
        const searchInput = document.getElementById('search');
        const tickerBolsaInput = document.getElementById('ticker-bolsa');
        const tickerInput = document.getElementById('ticker-input');
        const addTickerButton = document.getElementById('add-ticker');
        const tickersList = document.getElementById('tickers-list');

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[char]));
        }

        function showNotice(message, type = 'success') {
            notice.textContent = message;
            notice.className = `mb-5 rounded-2xl border px-4 py-3 text-sm ${type === 'error' ? 'border-red-500/40 bg-red-950/60 text-red-100' : 'border-emerald-500/40 bg-emerald-950/60 text-emerald-100'}`;
        }

        function setFormOpen(open) {
            formPanel.classList.toggle('hidden', !open);
            toggleCompanyForm.setAttribute('aria-expanded', String(open));
            companyFormIcon.textContent = open ? '−' : '+';
        }

        function renderSetores() {
            setorInput.innerHTML = '<option value="">Selecione um setor</option>' + state.setores.map(setor => `<option value="${escapeHtml(setor.id)}">${escapeHtml(setor.nome)}</option>`).join('');
        }

        function renderTickersList() {
            tickersList.innerHTML = state.tickers.length ? state.tickers.map((item, index) => `
                <span class="inline-flex items-center gap-2 rounded-full border border-slate-700 bg-slate-900 px-3 py-1">
                    <strong class="text-cyan-300">${escapeHtml(item.bolsa)}</strong> ${escapeHtml(item.ticker)}
                    <button type="button" data-remove-ticker="${index}" class="text-red-300 hover:text-red-200">×</button>
                </span>`).join('') : '<span class="text-slate-500">Nenhum ticker informado.</span>';
        }

        function resetForm() {
            idInput.value = '';
            form.reset();
            state.tickers = [];
            renderTickersList();
            submitButton.textContent = 'Cadastrar';
            cancelButton.classList.add('hidden');
            companyFormTitle.textContent = 'Cadastrar empresa';
            setFormOpen(false);
        }

        function render() {
            if (!state.empresas.length) {
                tbody.innerHTML = '<tr><td colspan="5" class="px-6 py-10 text-center text-slate-400">Nenhuma empresa encontrada.</td></tr>';
                return;
            }

            tbody.innerHTML = state.empresas.map(company => {
                const bvmfTickers = (company.tickers || []).filter(item => item.bolsa === 'BVMF').map(item => item.ticker).join(', ') || '—';

                return `
                <tr class="hover:bg-slate-800/50">
                    <td class="px-6 py-4 font-medium text-white"><a class="text-cyan-300 transition hover:text-cyan-200 hover:underline" href="resultados.php?empresa=${company.id}">${escapeHtml(company.nome_da_empresa)}</a></td>
                    <td class="px-6 py-4 text-slate-300">${escapeHtml(bvmfTickers)}</td>
                    <td class="px-6 py-4 text-slate-300">${escapeHtml(company.setor || '—')}</td>
                    <td class="px-6 py-4 text-slate-300">${company.cod_cvm ?? '—'}</td>
                    <td class="px-6 py-4">
                        <div class="flex justify-end gap-3">
                            <button data-edit="${company.id}" class="rounded-lg border border-cyan-500/40 px-3 py-2 text-cyan-300 transition hover:bg-cyan-500/10">Editar</button>
                            <button data-delete="${company.id}" class="rounded-lg border border-red-500/40 px-3 py-2 text-red-300 transition hover:bg-red-500/10">Excluir</button>
                        </div>
                    </td>
                </tr>`;
            }).join('');
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
            state.setores = data.setores || [];
            renderSetores();
            render();
        }

        form.addEventListener('submit', async event => {
            event.preventDefault();
            try {
                const id = idInput.value;
                const body = { id, nome_da_empresa: nomeInput.value, cod_cvm: codCvmInput.value, id_setor: setorInput.value, tickers: state.tickers };
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
                setorInput.value = company.id_setor ?? '';
                state.tickers = (company.tickers || []).map(item => ({ bolsa: item.bolsa, ticker: item.ticker }));
                renderTickersList();
                submitButton.textContent = 'Salvar';
                cancelButton.classList.remove('hidden');
                companyFormTitle.textContent = `Editando: ${company.nome_da_empresa}`;
                setFormOpen(true);
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

        addTickerButton.addEventListener('click', () => {
            const ticker = tickerInput.value.trim().toUpperCase();
            const bolsa = tickerBolsaInput.value;

            if (!ticker) {
                tickerInput.focus();
                return;
            }

            state.tickers.push({ bolsa, ticker });
            tickerInput.value = '';
            renderTickersList();
            tickerInput.focus();
        });

        tickersList.addEventListener('click', event => {
            const index = event.target.dataset.removeTicker;
            if (index === undefined) return;
            state.tickers.splice(Number(index), 1);
            renderTickersList();
        });

        toggleCompanyForm.addEventListener('click', () => setFormOpen(formPanel.classList.contains('hidden')));
        cancelButton.addEventListener('click', resetForm);
        searchInput.addEventListener('input', () => {
            clearTimeout(state.timer);
            state.timer = setTimeout(async () => {
                state.q = searchInput.value;
                try { await loadCompanies(); } catch (error) { showNotice(error.message, 'error'); }
            }, 180);
        });

        renderTickersList();
        loadCompanies().catch(error => showNotice(error.message, 'error'));
    </script>
</body>
</html>
