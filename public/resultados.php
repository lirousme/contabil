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

function referenciaLabel(array $referencia): string
{
    $ano = substr((string) $referencia['ano'], -2);
    return match ($referencia['periodo_base']) {
        'trimestre' => (int) $referencia['periodo_referencia'] . 'T' . $ano,
        'semestre' => (int) $referencia['periodo_referencia'] . 'S' . $ano,
        default => 'A' . $ano,
    };
}

function validateIndicador(array $data): array
{
    $nome = trim($data['nome'] ?? '');
    $descricao = trim($data['descricao'] ?? '');
    $formato = trim($data['formato'] ?? '');
    $formatos = ['Moeda', 'Porcentagem', 'Data', 'Texto'];
    if ($nome === '') {
        throw new InvalidArgumentException('Informe o nome do indicador.');
    }
    if (!in_array($formato, $formatos, true)) {
        throw new InvalidArgumentException('Escolha um formato válido.');
    }
    return [$nome, $descricao, $formato];
}

function validateReferencia(array $data, int $empresaId): array
{
    $periodoBase = $data['periodo_base'] ?? '';
    $ano = (int) ($data['ano'] ?? 0);
    $periodoReferencia = $data['periodo_referencia'] ?? null;
    if (!in_array($periodoBase, ['trimestre', 'semestre', 'anual'], true)) {
        throw new InvalidArgumentException('Escolha um período válido.');
    }
    if ($ano < 2016 || $ano > 2027) {
        throw new InvalidArgumentException('Escolha um ano entre 2016 e 2027.');
    }
    if ($periodoBase === 'anual') {
        $periodoReferencia = null;
    } else {
        $periodoReferencia = (int) $periodoReferencia;
        $max = $periodoBase === 'trimestre' ? 4 : 2;
        if ($periodoReferencia < 1 || $periodoReferencia > $max) {
            throw new InvalidArgumentException('Escolha uma referência válida para o período.');
        }
    }
    return [$periodoBase, $periodoReferencia, $ano, $empresaId];
}

try {
    $pdo = db();
    ensureResultadosTables($pdo);
    $empresaId = (int) ($_GET['empresa'] ?? $_GET['id_empresa'] ?? 0);
    if ($empresaId <= 0) {
        throw new InvalidArgumentException('Empresa não informada.');
    }

    if (($_GET['api'] ?? '') === 'resultados') {
        $method = $_SERVER['REQUEST_METHOD'];
        $data = payload();

        if ($method === 'GET') {
            $empresaStmt = $pdo->prepare('SELECT id, nome_da_empresa FROM empresas WHERE id = :id');
            $empresaStmt->execute(['id' => $empresaId]);
            $empresa = $empresaStmt->fetch();
            if (!$empresa) {
                jsonResponse(['error' => 'Empresa não encontrada.'], 404);
            }

            $indicadores = $pdo->query('SELECT id, nome, descricao, formato FROM indicadores ORDER BY nome')->fetchAll();
            $referenciasStmt = $pdo->prepare('SELECT id, periodo_base, periodo_referencia, ano FROM referencias WHERE id_empresa = :id_empresa ORDER BY ano, FIELD(periodo_base, "anual", "semestre", "trimestre"), periodo_referencia');
            $referenciasStmt->execute(['id_empresa' => $empresaId]);
            $referencias = array_map(function (array $referencia): array {
                $referencia['label'] = referenciaLabel($referencia);
                return $referencia;
            }, $referenciasStmt->fetchAll());
            $resultadosStmt = $pdo->prepare('SELECT resultados.* FROM resultados INNER JOIN referencias ON referencias.id = resultados.id_referencia WHERE referencias.id_empresa = :id_empresa');
            $resultadosStmt->execute(['id_empresa' => $empresaId]);
            jsonResponse(['empresa' => $empresa, 'indicadores' => $indicadores, 'referencias' => $referencias, 'resultados' => $resultadosStmt->fetchAll()]);
        }

        if ($method === 'POST') {
            $action = $data['action'] ?? '';
            if ($action === 'indicador') {
                [$nome, $descricao, $formato] = validateIndicador($data);
                $stmt = $pdo->prepare('INSERT INTO indicadores (nome, descricao, formato) VALUES (:nome, :descricao, :formato)');
                $stmt->execute(['nome' => $nome, 'descricao' => $descricao, 'formato' => $formato]);
                jsonResponse(['message' => 'Indicador cadastrado.'], 201);
            }
            if ($action === 'referencia') {
                [$periodoBase, $periodoReferencia, $ano, $idEmpresa] = validateReferencia($data, $empresaId);
                $stmt = $pdo->prepare('INSERT INTO referencias (periodo_base, periodo_referencia, ano, id_empresa) VALUES (:periodo_base, :periodo_referencia, :ano, :id_empresa)');
                $stmt->execute(['periodo_base' => $periodoBase, 'periodo_referencia' => $periodoReferencia, 'ano' => $ano, 'id_empresa' => $idEmpresa]);
                jsonResponse(['message' => 'Referência cadastrada.'], 201);
            }
            if ($action === 'resultado') {
                $indicadorId = (int) ($data['id_indicador'] ?? 0);
                $referenciaId = (int) ($data['id_referencia'] ?? 0);
                $valor = trim((string) ($data['valor'] ?? ''));
                $stmt = $pdo->prepare('SELECT formato FROM indicadores WHERE id = :id');
                $stmt->execute(['id' => $indicadorId]);
                $formato = $stmt->fetchColumn();
                if (!$formato) throw new InvalidArgumentException('Indicador inválido.');
                $check = $pdo->prepare('SELECT COUNT(*) FROM referencias WHERE id = :id AND id_empresa = :id_empresa');
                $check->execute(['id' => $referenciaId, 'id_empresa' => $empresaId]);
                if ((int) $check->fetchColumn() === 0) throw new InvalidArgumentException('Referência inválida.');
                $fields = ['data' => null, 'decimal' => null, 'texto' => null];
                if ($valor !== '') {
                    if ($formato === 'Data') $fields['data'] = $valor;
                    elseif ($formato === 'Texto') $fields['texto'] = $valor;
                    else $fields['decimal'] = str_replace(',', '.', $valor);
                }
                $upsert = $pdo->prepare('INSERT INTO resultados (id_referencia, id_indicador, `data`, `decimal`, texto) VALUES (:id_referencia, :id_indicador, :data, :decimal, :texto) ON DUPLICATE KEY UPDATE `data` = VALUES(`data`), `decimal` = VALUES(`decimal`), texto = VALUES(texto)');
                $upsert->execute(['id_referencia' => $referenciaId, 'id_indicador' => $indicadorId, 'data' => $fields['data'], 'decimal' => $fields['decimal'], 'texto' => $fields['texto']]);
                jsonResponse(['message' => 'Resultado salvo.']);
            }
        }
        jsonResponse(['error' => 'Método não permitido.'], 405);
    }
} catch (InvalidArgumentException $exception) {
    if (($_GET['api'] ?? '') === 'resultados') jsonResponse(['error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    if (($_GET['api'] ?? '') === 'resultados') jsonResponse(['error' => 'Não foi possível preparar os resultados.'], 503);
}
?>
<!doctype html>
<html lang="pt-BR" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Resultados</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' };</script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
    <main class="mx-auto flex w-full max-w-7xl flex-col gap-8 px-4 py-10 sm:px-6 lg:px-8">
        <section class="rounded-3xl border border-slate-800 bg-slate-900/80 p-6 shadow-2xl shadow-slate-950/40">
            <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                <div>
                    <a href="index.php" class="text-sm font-semibold text-cyan-300 hover:text-cyan-200">← Empresas</a>
                    <p class="mt-4 text-sm font-semibold uppercase tracking-[0.3em] text-cyan-400">Resultados</p>
                    <h1 id="empresa-title" class="mt-2 text-3xl font-bold tracking-tight text-white">Carregando...</h1>
                    <p class="mt-2 text-slate-400">Dê dois cliques em uma célula para editar o valor do indicador por referência.</p>
                </div>
                <div class="flex gap-3">
                    <button id="add-reference" class="rounded-xl border border-cyan-500/40 px-5 py-3 font-semibold text-cyan-300 transition hover:bg-cyan-500/10">+T</button>
                    <button id="add-indicator" class="rounded-xl bg-cyan-500 px-5 py-3 font-semibold text-slate-950 transition hover:bg-cyan-400">+</button>
                </div>
            </div>
            <div id="notice" class="mt-5 hidden rounded-2xl border px-4 py-3 text-sm"></div>
        </section>
        <section class="overflow-hidden rounded-3xl border border-slate-800 bg-slate-900/80 shadow-2xl shadow-slate-950/40">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-800">
                    <thead id="results-head" class="bg-slate-950/80 text-left text-xs font-semibold uppercase tracking-wider text-slate-400"></thead>
                    <tbody id="results-body" class="divide-y divide-slate-800 text-sm"></tbody>
                </table>
            </div>
        </section>
    </main>

    <div id="indicator-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/80 p-4">
        <form id="indicator-form" class="w-full max-w-lg rounded-3xl border border-slate-800 bg-slate-900 p-6 shadow-2xl">
            <h2 class="text-xl font-bold text-white">Novo indicador</h2>
            <label class="mt-4 block"><span class="mb-2 block text-sm text-slate-300">Nome</span><input id="indicator-name" required class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white outline-none focus:border-cyan-400 focus:ring-2 focus:ring-cyan-500"></label>
            <label class="mt-4 block"><span class="mb-2 block text-sm text-slate-300">Descrição</span><textarea id="indicator-description" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white outline-none focus:border-cyan-400 focus:ring-2 focus:ring-cyan-500"></textarea></label>
            <label class="mt-4 block"><span class="mb-2 block text-sm text-slate-300">Formato</span><select id="indicator-format" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white outline-none focus:border-cyan-400 focus:ring-2 focus:ring-cyan-500"><option>Moeda</option><option>Porcentagem</option><option>Data</option><option>Texto</option></select></label>
            <div class="mt-6 flex justify-end gap-3"><button type="button" data-close class="rounded-xl border border-slate-700 px-5 py-3 font-semibold text-slate-200 hover:bg-slate-800">Cancelar</button><button class="rounded-xl bg-cyan-500 px-5 py-3 font-semibold text-slate-950 hover:bg-cyan-400">Salvar</button></div>
        </form>
    </div>

    <div id="reference-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/80 p-4">
        <form id="reference-form" class="w-full max-w-lg rounded-3xl border border-slate-800 bg-slate-900 p-6 shadow-2xl">
            <h2 class="text-xl font-bold text-white">Nova referência</h2>
            <label class="mt-4 block"><span class="mb-2 block text-sm text-slate-300">Período</span><select id="period-base" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white"><option value="anual">Anual</option><option value="semestre">Semestre</option><option value="trimestre">Trimestre</option></select></label>
            <label id="period-reference-wrap" class="mt-4 hidden"><span class="mb-2 block text-sm text-slate-300">Referência</span><select id="period-reference" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white"></select></label>
            <label class="mt-4 block"><span class="mb-2 block text-sm text-slate-300">Ano</span><select id="period-year" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white"></select></label>
            <div class="mt-6 flex justify-end gap-3"><button type="button" data-close class="rounded-xl border border-slate-700 px-5 py-3 font-semibold text-slate-200 hover:bg-slate-800">Cancelar</button><button class="rounded-xl bg-cyan-500 px-5 py-3 font-semibold text-slate-950 hover:bg-cyan-400">Salvar</button></div>
        </form>
    </div>

    <script>
        const empresaId = <?= (int) ($_GET['empresa'] ?? $_GET['id_empresa'] ?? 0) ?>;
        const apiUrl = `<?= e($_SERVER['SCRIPT_NAME'] ?? '/resultados.php') ?>?api=resultados&empresa=${empresaId}`;
        const state = { indicadores: [], referencias: [], resultados: [] };
        const notice = document.getElementById('notice');
        const head = document.getElementById('results-head');
        const body = document.getElementById('results-body');
        const empresaTitle = document.getElementById('empresa-title');
        const indicatorModal = document.getElementById('indicator-modal');
        const referenceModal = document.getElementById('reference-modal');
        const periodBase = document.getElementById('period-base');
        const periodReference = document.getElementById('period-reference');
        const periodReferenceWrap = document.getElementById('period-reference-wrap');
        const periodYear = document.getElementById('period-year');

        function escapeHtml(value) { return String(value ?? '').replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[char])); }
        function showNotice(message, type = 'success') { notice.textContent = message; notice.className = `mt-5 rounded-2xl border px-4 py-3 text-sm ${type === 'error' ? 'border-red-500/40 bg-red-950/60 text-red-100' : 'border-emerald-500/40 bg-emerald-950/60 text-emerald-100'}`; }
        function openModal(modal) { modal.classList.remove('hidden'); modal.classList.add('flex'); }
        function closeModals() { [indicatorModal, referenceModal].forEach(modal => { modal.classList.add('hidden'); modal.classList.remove('flex'); }); }
        function resultFor(indicadorId, referenciaId) { return state.resultados.find(item => Number(item.id_indicador) === Number(indicadorId) && Number(item.id_referencia) === Number(referenciaId)); }
        function displayValue(indicador, resultado) { if (!resultado) return ''; if (indicador.formato === 'Data') return resultado.data ?? ''; if (indicador.formato === 'Texto') return resultado.texto ?? ''; return resultado.decimal ?? ''; }

        function render() {
            head.innerHTML = `<tr><th class="sticky left-0 z-20 bg-slate-950 px-6 py-4">Indicador</th><th class="px-6 py-4">Descrição</th>${state.referencias.map(ref => `<th class="px-6 py-4 text-center">${escapeHtml(ref.label)}</th>`).join('')}</tr>`;
            if (!state.indicadores.length) { body.innerHTML = `<tr><td colspan="${2 + state.referencias.length}" class="px-6 py-10 text-center text-slate-400">Nenhum indicador cadastrado.</td></tr>`; return; }
            body.innerHTML = state.indicadores.map(indicador => `<tr class="hover:bg-slate-800/50"><td class="sticky left-0 z-10 bg-slate-900 px-6 py-4 font-medium text-white">${escapeHtml(indicador.nome)}</td><td class="px-6 py-4 text-slate-300">${escapeHtml(indicador.descricao || '—')}</td>${state.referencias.map(ref => `<td data-indicador="${indicador.id}" data-referencia="${ref.id}" class="min-w-32 cursor-cell px-6 py-4 text-center text-slate-200 hover:bg-cyan-500/10">${escapeHtml(displayValue(indicador, resultFor(indicador.id, ref.id)) || '—')}</td>`).join('')}</tr>`).join('');
        }

        async function request(method = 'GET', data = null) { const response = await fetch(apiUrl, { method, headers: { 'Content-Type': 'application/json' }, body: data ? JSON.stringify(data) : null }); const json = await response.json(); if (!response.ok) throw new Error(json.error || 'Erro inesperado.'); return json; }
        async function load() { const data = await request(); empresaTitle.textContent = data.empresa.nome_da_empresa; state.indicadores = data.indicadores; state.referencias = data.referencias; state.resultados = data.resultados; render(); }
        function updatePeriodOptions() { const base = periodBase.value; periodReference.innerHTML = ''; periodReferenceWrap.classList.toggle('hidden', base === 'anual'); const max = base === 'trimestre' ? 4 : 2; for (let i = 1; i <= max; i++) periodReference.insertAdjacentHTML('beforeend', `<option value="${i}">${i}</option>`); }

        document.getElementById('add-indicator').addEventListener('click', () => openModal(indicatorModal));
        document.getElementById('add-reference').addEventListener('click', () => openModal(referenceModal));
        document.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', closeModals));
        periodBase.addEventListener('change', updatePeriodOptions);
        for (let year = 2016; year <= 2027; year++) periodYear.insertAdjacentHTML('beforeend', `<option value="${year}">${year}</option>`);
        updatePeriodOptions();

        document.getElementById('indicator-form').addEventListener('submit', async event => { event.preventDefault(); try { await request('POST', { action: 'indicador', nome: document.getElementById('indicator-name').value, descricao: document.getElementById('indicator-description').value, formato: document.getElementById('indicator-format').value }); closeModals(); event.target.reset(); showNotice('Indicador cadastrado.'); await load(); } catch (error) { showNotice(error.message, 'error'); } });
        document.getElementById('reference-form').addEventListener('submit', async event => { event.preventDefault(); try { await request('POST', { action: 'referencia', periodo_base: periodBase.value, periodo_referencia: periodReference.value, ano: periodYear.value }); closeModals(); showNotice('Referência cadastrada.'); await load(); } catch (error) { showNotice(error.message, 'error'); } });
        body.addEventListener('dblclick', async event => { const cell = event.target.closest('[data-indicador][data-referencia]'); if (!cell) return; const indicador = state.indicadores.find(item => Number(item.id) === Number(cell.dataset.indicador)); const current = cell.textContent === '—' ? '' : cell.textContent.trim(); const input = document.createElement('input'); input.type = indicador.formato === 'Data' ? 'date' : 'text'; input.value = current; input.className = 'w-full rounded-lg border border-cyan-500 bg-slate-950 px-2 py-1 text-center text-white outline-none'; cell.textContent = ''; cell.appendChild(input); input.focus(); async function save() { try { await request('POST', { action: 'resultado', id_indicador: cell.dataset.indicador, id_referencia: cell.dataset.referencia, valor: input.value }); await load(); } catch (error) { showNotice(error.message, 'error'); await load(); } } input.addEventListener('blur', save, { once: true }); input.addEventListener('keydown', event => { if (event.key === 'Enter') input.blur(); }); });
        load().catch(error => showNotice(error.message, 'error'));
    </script>
</body>
</html>
