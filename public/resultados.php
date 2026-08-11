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

function referenciasDisponiveis(): array
{
    $referencias = ['3T26', '2T26', '1T26'];
    for ($ano = 2025; $ano >= 2016; $ano--) {
        $anoCurto = substr((string) $ano, -2);
        array_push($referencias, 'A' . $anoCurto, '3T' . $anoCurto, '2T' . $anoCurto, '1T' . $anoCurto);
    }
    return $referencias;
}

function validateIndicador(array $data): array
{
    $nome = trim($data['nome'] ?? '');
    $descricao = trim($data['descricao'] ?? '');
    $formato = trim($data['formato'] ?? '');
    $formatos = ['Moeda', 'Porcentagem', 'Número Inteiro', 'Data', 'Texto'];
    if ($nome === '') {
        throw new InvalidArgumentException('Informe o nome do indicador.');
    }
    if (!in_array($formato, $formatos, true)) {
        throw new InvalidArgumentException('Escolha um formato válido.');
    }
    $respostasPreDefinidas = (int) ($data['respostas_pre_definidas'] ?? 0) === 1 ? 1 : 0;
    return [$nome, $descricao, $formato, $respostasPreDefinidas];
}


function parseDecimalValue(string $value): string
{
    $normalized = preg_replace('/[^\d,.-]/', '', trim($value));
    if ($normalized === null || $normalized === '' || !preg_match('/\d/', $normalized)) {
        throw new InvalidArgumentException('Informe um número válido.');
    }

    $negative = str_starts_with($normalized, '-');
    $normalized = str_replace('-', '', $normalized);
    $lastComma = strrpos($normalized, ',');
    $lastDot = strrpos($normalized, '.');
    $decimalSeparator = null;

    if ($lastComma !== false && $lastDot !== false) {
        $decimalSeparator = $lastComma > $lastDot ? ',' : '.';
    } elseif ($lastComma !== false) {
        $decimalSeparator = ',';
    } elseif ($lastDot !== false) {
        $dotCount = substr_count($normalized, '.');
        $digitsAfterDot = strlen($normalized) - $lastDot - 1;
        if ($dotCount === 1 && $digitsAfterDot > 0 && $digitsAfterDot !== 3) {
            $decimalSeparator = '.';
        }
    }

    if ($decimalSeparator !== null) {
        $separatorPosition = strrpos($normalized, $decimalSeparator);
        $integer = preg_replace('/\D/', '', substr($normalized, 0, $separatorPosition));
        $fraction = preg_replace('/\D/', '', substr($normalized, $separatorPosition + 1));
    } else {
        $integer = preg_replace('/\D/', '', $normalized);
        $fraction = '';
    }

    if ($integer === null || $integer === '') {
        $integer = '0';
    }
    if ($fraction === null) {
        $fraction = '';
    }

    $decimal = ltrim($integer, '0');
    if ($decimal === '') {
        $decimal = '0';
    }
    if ($fraction !== '') {
        $decimal .= '.' . $fraction;
    }

    return ($negative ? '-' : '') . $decimal;
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

            $indicadores = $pdo->query('SELECT id, nome, descricao, formato, respostas_pre_definidas FROM indicadores ORDER BY nome')->fetchAll();
            $referencias = referenciasDisponiveis();
            $resultadosStmt = $pdo->prepare('SELECT id_empresa, id_indicador, referencia, `data`, `decimal`, texto, id_resposta_definida FROM resultados WHERE id_empresa = :id_empresa');
            $resultadosStmt->execute(['id_empresa' => $empresaId]);
            $comentariosStmt = $pdo->prepare('SELECT id, comentario, id_indicador, id_empresa FROM comentarios_indicadores WHERE id_empresa = :id_empresa');
            $comentariosStmt->execute(['id_empresa' => $empresaId]);
            $ocultosStmt = $pdo->prepare('SELECT tipo, chave FROM resultados_ocultos WHERE id_empresa = :id_empresa');
            $ocultosStmt->execute(['id_empresa' => $empresaId]);
            $respostasPreDefinidas = $pdo->query('SELECT id, id_indicador, texto, ponto FROM respostas_pre_definidas ORDER BY id_indicador, texto')->fetchAll();
            jsonResponse(['empresa' => $empresa, 'indicadores' => $indicadores, 'referencias' => $referencias, 'resultados' => $resultadosStmt->fetchAll(), 'comentarios' => $comentariosStmt->fetchAll(), 'ocultos' => $ocultosStmt->fetchAll(), 'respostas_pre_definidas' => $respostasPreDefinidas]);
        }

        if ($method === 'POST') {
            $action = $data['action'] ?? '';
            if ($action === 'indicador') {
                [$nome, $descricao, $formato, $respostasPreDefinidas] = validateIndicador($data);
                $stmt = $pdo->prepare('INSERT INTO indicadores (nome, descricao, formato, respostas_pre_definidas) VALUES (:nome, :descricao, :formato, :respostas_pre_definidas)');
                $stmt->execute(['nome' => $nome, 'descricao' => $descricao, 'formato' => $formato, 'respostas_pre_definidas' => $respostasPreDefinidas]);
                jsonResponse(['message' => 'Indicador cadastrado.'], 201);
            }
            if ($action === 'indicador_update') {
                $indicadorId = (int) ($data['id'] ?? 0);
                $field = $data['field'] ?? '';
                if ($indicadorId <= 0 || !in_array($field, ['nome', 'descricao'], true)) {
                    throw new InvalidArgumentException('Indicador inválido.');
                }
                $value = trim((string) ($data['value'] ?? ''));
                if ($field === 'nome' && $value === '') {
                    throw new InvalidArgumentException('Informe o nome do indicador.');
                }
                $stmt = $pdo->prepare(sprintf('UPDATE indicadores SET %s = :value WHERE id = :id', $field));
                $stmt->execute(['value' => $value, 'id' => $indicadorId]);
                if ($stmt->rowCount() === 0) {
                    $check = $pdo->prepare('SELECT COUNT(*) FROM indicadores WHERE id = :id');
                    $check->execute(['id' => $indicadorId]);
                    if ((int) $check->fetchColumn() === 0) throw new InvalidArgumentException('Indicador inválido.');
                }
                jsonResponse(['message' => 'Indicador atualizado.']);
            }
            if ($action === 'resultado') {
                $indicadorId = (int) ($data['id_indicador'] ?? 0);
                $referencia = (string) ($data['referencia'] ?? '');
                $valor = trim((string) ($data['valor'] ?? ''));
                $stmt = $pdo->prepare('SELECT formato, respostas_pre_definidas FROM indicadores WHERE id = :id');
                $stmt->execute(['id' => $indicadorId]);
                $indicadorResultado = $stmt->fetch();
                if (!$indicadorResultado) throw new InvalidArgumentException('Indicador inválido.');
                if (!in_array($referencia, referenciasDisponiveis(), true)) throw new InvalidArgumentException('Referência inválida.');
                $fields = ['data' => null, 'decimal' => null, 'texto' => null, 'id_resposta_definida' => null];
                if ((int) $indicadorResultado['respostas_pre_definidas'] === 1) {
                    if ($valor !== '') {
                        $respostaId = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        $respostaStmt = $pdo->prepare('SELECT COUNT(*) FROM respostas_pre_definidas WHERE id = :id AND id_indicador = :id_indicador');
                        $respostaStmt->execute(['id' => $respostaId ?: 0, 'id_indicador' => $indicadorId]);
                        if (!$respostaId || (int) $respostaStmt->fetchColumn() === 0) throw new InvalidArgumentException('Resposta pré-definida inválida.');
                        $fields['id_resposta_definida'] = $respostaId;
                    }
                } elseif ($valor !== '') {
                    if ($indicadorResultado['formato'] === 'Data') $fields['data'] = $valor;
                    elseif ($indicadorResultado['formato'] === 'Texto') $fields['texto'] = $valor;
                    else $fields['decimal'] = parseDecimalValue($valor);
                }
                $upsert = $pdo->prepare('INSERT INTO resultados (id_empresa, id_indicador, referencia, `data`, `decimal`, texto, id_resposta_definida) VALUES (:id_empresa, :id_indicador, :referencia, :data, :decimal, :texto, :id_resposta_definida) ON DUPLICATE KEY UPDATE `data` = VALUES(`data`), `decimal` = VALUES(`decimal`), texto = VALUES(texto), id_resposta_definida = VALUES(id_resposta_definida)');
                $upsert->execute(['id_empresa' => $empresaId, 'id_indicador' => $indicadorId, 'referencia' => $referencia, 'data' => $fields['data'], 'decimal' => $fields['decimal'], 'texto' => $fields['texto'], 'id_resposta_definida' => $fields['id_resposta_definida']]);
                jsonResponse(['message' => 'Resultado salvo.']);
            }
            if ($action === 'resposta_pre_definida_add') {
                $indicadorId = (int) ($data['id_indicador'] ?? 0);
                $texto = trim((string) ($data['texto'] ?? ''));
                $ponto = filter_var($data['ponto'] ?? null, FILTER_VALIDATE_INT);
                $stmt = $pdo->prepare('SELECT respostas_pre_definidas FROM indicadores WHERE id = :id');
                $stmt->execute(['id' => $indicadorId]);
                $indicadorResposta = $stmt->fetch();
                if ($indicadorId <= 0 || !$indicadorResposta || (int) $indicadorResposta['respostas_pre_definidas'] !== 1) {
                    throw new InvalidArgumentException('Indicador sem respostas pré-definidas.');
                }
                if ($texto === '') throw new InvalidArgumentException('Informe o texto da resposta.');
                if ($ponto === false) throw new InvalidArgumentException('Informe uma pontuação inteira.');
                $stmt = $pdo->prepare('INSERT INTO respostas_pre_definidas (id_indicador, texto, ponto) VALUES (:id_indicador, :texto, :ponto)');
                $stmt->execute(['id_indicador' => $indicadorId, 'texto' => $texto, 'ponto' => $ponto]);
                jsonResponse(['message' => 'Resposta pré-definida cadastrada.'], 201);
            }
            if ($action === 'resposta_pre_definida_delete') {
                $respostaId = (int) ($data['id'] ?? 0);
                $stmt = $pdo->prepare('DELETE FROM respostas_pre_definidas WHERE id = :id');
                $stmt->execute(['id' => $respostaId]);
                if ($respostaId <= 0 || $stmt->rowCount() === 0) throw new InvalidArgumentException('Resposta pré-definida inválida.');
                jsonResponse(['message' => 'Resposta pré-definida removida.']);
            }
            if ($action === 'comentario') {
                $indicadorId = (int) ($data['id_indicador'] ?? 0);
                $comentario = trim((string) ($data['comentario'] ?? ''));
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM indicadores WHERE id = :id');
                $stmt->execute(['id' => $indicadorId]);
                if ($indicadorId <= 0 || (int) $stmt->fetchColumn() === 0) {
                    throw new InvalidArgumentException('Indicador inválido.');
                }
                $upsert = $pdo->prepare('INSERT INTO comentarios_indicadores (comentario, id_indicador, id_empresa) VALUES (:comentario, :id_indicador, :id_empresa) ON DUPLICATE KEY UPDATE comentario = VALUES(comentario)');
                $upsert->execute(['comentario' => $comentario, 'id_indicador' => $indicadorId, 'id_empresa' => $empresaId]);
                jsonResponse(['message' => 'Comentário salvo.']);
            }
            if ($action === 'visibilidade') {
                $tipo = (string) ($data['tipo'] ?? '');
                $chave = (string) ($data['chave'] ?? '');
                $oculto = filter_var($data['oculto'] ?? false, FILTER_VALIDATE_BOOLEAN);
                if ($tipo === 'linha') {
                    $indicadorId = filter_var($chave, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    $stmt = $pdo->prepare('SELECT COUNT(*) FROM indicadores WHERE id = :id');
                    $stmt->execute(['id' => $indicadorId ?: 0]);
                    if (!$indicadorId || (int) $stmt->fetchColumn() === 0) throw new InvalidArgumentException('Indicador inválido.');
                    $chave = (string) $indicadorId;
                } elseif ($tipo === 'coluna') {
                    if (!in_array($chave, referenciasDisponiveis(), true) && !in_array($chave, ['descricao', 'comentario'], true)) throw new InvalidArgumentException('Coluna inválida.');
                } else {
                    throw new InvalidArgumentException('Tipo de visibilidade inválido.');
                }
                if ($oculto) {
                    $stmt = $pdo->prepare('INSERT IGNORE INTO resultados_ocultos (id_empresa, tipo, chave) VALUES (:id_empresa, :tipo, :chave)');
                } else {
                    $stmt = $pdo->prepare('DELETE FROM resultados_ocultos WHERE id_empresa = :id_empresa AND tipo = :tipo AND chave = :chave');
                }
                $stmt->execute(['id_empresa' => $empresaId, 'tipo' => $tipo, 'chave' => $chave]);
                jsonResponse(['message' => $oculto ? 'Item ocultado.' : 'Item exibido.']);
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
<body class="h-screen overflow-hidden bg-slate-950 text-slate-100 antialiased">
    <main class="flex h-full w-full flex-col gap-2 p-2 sm:p-3">
        <header class="flex shrink-0 flex-wrap items-center gap-2 rounded-xl border border-slate-800 bg-slate-900/90 px-3 py-2 shadow-lg shadow-slate-950/30">
            <a href="index.php" class="rounded-lg px-2 py-2 text-sm font-semibold text-cyan-300 transition hover:bg-slate-800 hover:text-cyan-200" aria-label="Voltar para empresas">← Empresas</a>
            <div class="h-6 w-px bg-slate-700" aria-hidden="true"></div>
            <h1 id="empresa-title" class="min-w-0 flex-1 truncate text-sm font-semibold text-white">Carregando...</h1>
            <button id="toggle-indicator-column" type="button" class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm font-semibold text-slate-200 transition hover:border-cyan-500 hover:text-cyan-300 sm:hidden" aria-pressed="false" title="Recolher coluna de indicadores">Recolher indicadores</button>
            <button id="toggle-hidden" type="button" class="flex items-center gap-2 rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm font-semibold text-slate-200 transition hover:border-cyan-500 hover:text-cyan-300" aria-pressed="false" title="Mostrar linhas e colunas ocultas">
                <span id="toggle-hidden-icon" aria-hidden="true"></span><span class="hidden sm:inline">Ocultos</span>
            </button>
            <div class="flex items-center gap-2" aria-label="Calculadora rápida">
                <label for="quick-multiplier" class="hidden text-xs font-semibold uppercase tracking-wider text-cyan-300 sm:block">x 1.000</label>
                <input id="quick-multiplier" type="text" inputmode="decimal" placeholder="Número × 1.000" class="w-36 rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white outline-none transition placeholder:text-slate-500 focus:border-cyan-400 focus:ring-2 focus:ring-cyan-500 sm:w-40" autocomplete="off">
                <button id="quick-copy" type="button" class="rounded-lg bg-slate-700 px-3 py-2 text-sm font-bold text-white transition hover:bg-slate-600" title="Multiplicar por 1.000 e copiar">Copiar</button>
                <span id="quick-copy-status" class="sr-only" aria-live="polite">Multiplica e copia.</span>
            </div>
            <button id="add-indicator" class="rounded-lg bg-cyan-500 px-3 py-2 text-sm font-bold text-slate-950 transition hover:bg-cyan-400" title="Adicionar indicador">+ Indicador</button>
            <div id="notice" class="hidden basis-full rounded-lg border px-3 py-2 text-sm"></div>
        </header>
        <section class="min-h-0 flex-1 overflow-hidden rounded-xl border border-slate-800 bg-slate-900/80 shadow-2xl shadow-slate-950/40">
            <div class="h-full w-full overflow-auto" id="results-scroll">
                <table class="min-w-full w-max divide-y divide-slate-800">
                    <thead id="results-head" class="sticky top-0 z-30 bg-slate-950 text-left text-xs font-semibold uppercase tracking-wider text-slate-400"></thead>
                    <tbody id="results-body" class="divide-y divide-slate-800 text-sm"></tbody>
                </table>
            </div>
        </section>
    </main>

    <div id="responses-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/80 p-4">
        <div class="w-full max-w-2xl rounded-3xl border border-slate-800 bg-slate-900 p-6 shadow-2xl">
            <div class="flex items-start justify-between gap-4"><div><h2 class="text-xl font-bold text-white">Respostas possíveis</h2><p id="responses-indicator-name" class="mt-1 text-sm text-slate-400"></p></div><button type="button" data-close class="rounded-lg border border-slate-700 px-3 py-2 text-sm font-semibold text-slate-200 hover:bg-slate-800">Fechar</button></div>
            <div id="responses-list" class="mt-5 space-y-2"></div>
            <form id="responses-form" class="mt-5 grid gap-3 sm:grid-cols-[1fr_8rem_auto]">
                <input id="response-text" required placeholder="Nova resposta" class="rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white outline-none focus:border-cyan-400 focus:ring-2 focus:ring-cyan-500">
                <input id="response-point" required type="number" step="1" placeholder="Ponto" class="rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white outline-none focus:border-cyan-400 focus:ring-2 focus:ring-cyan-500">
                <button class="rounded-xl bg-cyan-500 px-5 py-3 font-semibold text-slate-950 hover:bg-cyan-400">Adicionar</button>
            </form>
        </div>
    </div>

    <div id="indicator-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/80 p-4">
        <form id="indicator-form" class="w-full max-w-lg rounded-3xl border border-slate-800 bg-slate-900 p-6 shadow-2xl">
            <h2 class="text-xl font-bold text-white">Novo indicador</h2>
            <label class="mt-4 block"><span class="mb-2 block text-sm text-slate-300">Nome</span><input id="indicator-name" required class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white outline-none focus:border-cyan-400 focus:ring-2 focus:ring-cyan-500"></label>
            <label class="mt-4 block"><span class="mb-2 block text-sm text-slate-300">Descrição</span><textarea id="indicator-description" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white outline-none focus:border-cyan-400 focus:ring-2 focus:ring-cyan-500"></textarea></label>
            <label class="mt-4 block"><span class="mb-2 block text-sm text-slate-300">Formato</span><select id="indicator-format" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white outline-none focus:border-cyan-400 focus:ring-2 focus:ring-cyan-500"><option>Moeda</option><option>Porcentagem</option><option>Número Inteiro</option><option>Data</option><option>Texto</option></select></label>
            <label class="mt-4 block"><span class="mb-2 block text-sm text-slate-300">Tipo de resposta</span><select id="indicator-response-type" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white outline-none focus:border-cyan-400 focus:ring-2 focus:ring-cyan-500"><option value="0" selected>Resposta aberta</option><option value="1">Respostas pré-definidas</option></select></label>
            <div class="mt-6 flex justify-end gap-3"><button type="button" data-close class="rounded-xl border border-slate-700 px-5 py-3 font-semibold text-slate-200 hover:bg-slate-800">Cancelar</button><button class="rounded-xl bg-cyan-500 px-5 py-3 font-semibold text-slate-950 hover:bg-cyan-400">Salvar</button></div>
        </form>
    </div>

    <script>
        const empresaId = <?= (int) ($_GET['empresa'] ?? $_GET['id_empresa'] ?? 0) ?>;
        const apiUrl = `<?= e($_SERVER['SCRIPT_NAME'] ?? '/resultados.php') ?>?api=resultados&empresa=${empresaId}`;
        const state = { indicadores: [], referencias: [], resultados: [], comentarios: [], ocultos: [], respostasPreDefinidas: [], mostrarOcultos: false, colunaIndicadoresRecolhida: false };
        const notice = document.getElementById('notice');
        const head = document.getElementById('results-head');
        const body = document.getElementById('results-body');
        const empresaTitle = document.getElementById('empresa-title');
        const indicatorModal = document.getElementById('indicator-modal');
        const responsesModal = document.getElementById('responses-modal');
        const responsesList = document.getElementById('responses-list');
        const responsesIndicatorName = document.getElementById('responses-indicator-name');
        let activeResponsesIndicatorId = null;
        const quickMultiplier = document.getElementById('quick-multiplier');
        const quickCopy = document.getElementById('quick-copy');
        const quickCopyStatus = document.getElementById('quick-copy-status');
        const toggleHidden = document.getElementById('toggle-hidden');
        const toggleHiddenIcon = document.getElementById('toggle-hidden-icon');
        const toggleIndicatorColumn = document.getElementById('toggle-indicator-column');

        const eyeOpen = '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
        const eyeClosed = '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m3 3 18 18"/><path d="M10.6 5.2A11 11 0 0 1 12 5c6.5 0 10 7 10 7a18 18 0 0 1-2.1 3.2M6.6 6.6C3.6 8.5 2 12 2 12s3.5 7 10 7a10 10 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';


        function parseQuickNumber(value) {
            const normalized = String(value ?? '').trim().replace(/\s/g, '');
            if (!normalized || !/\d/.test(normalized)) return null;
            let prepared = normalized.replace(/[^\d,.-]/g, '');
            const negative = prepared.startsWith('-');
            prepared = prepared.replace(/-/g, '');
            const lastComma = prepared.lastIndexOf(',');
            const lastDot = prepared.lastIndexOf('.');
            let decimalSeparator = null;
            if (lastComma !== -1 && lastDot !== -1) {
                decimalSeparator = lastComma > lastDot ? ',' : '.';
            } else if (lastComma !== -1) {
                decimalSeparator = ',';
            } else if (lastDot !== -1) {
                const dotCount = (prepared.match(/\./g) || []).length;
                const digitsAfterDot = prepared.length - lastDot - 1;
                if (dotCount === 1 && digitsAfterDot > 0 && digitsAfterDot !== 3) decimalSeparator = '.';
            }
            if (decimalSeparator) {
                const separatorPosition = prepared.lastIndexOf(decimalSeparator);
                prepared = prepared.slice(0, separatorPosition).replace(/\D/g, '') + '.' + prepared.slice(separatorPosition + 1).replace(/\D/g, '');
            } else {
                prepared = prepared.replace(/\D/g, '');
            }
            const number = Number((negative ? '-' : '') + prepared);
            return Number.isFinite(number) ? number : null;
        }
        function formatQuickResult(value) {
            return new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 10 }).format(value);
        }
        async function copyQuickResult() {
            const number = parseQuickNumber(quickMultiplier.value);
            if (number === null) {
                quickCopyStatus.textContent = 'Informe um número válido.';
                quickMultiplier.focus();
                return;
            }
            const result = formatQuickResult(number * 1000);
            try {
                await navigator.clipboard.writeText(result);
                quickCopyStatus.textContent = `Copiado: ${result}`;
                quickCopy.textContent = 'Copiado!';
                window.setTimeout(() => { quickCopy.textContent = 'Copiar'; }, 1500);
            } catch (error) {
                quickCopyStatus.textContent = 'Não foi possível copiar.';
            }
        }

        function escapeHtml(value) { return String(value ?? '').replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[char])); }
        function showNotice(message, type = 'success') { notice.textContent = message; notice.className = `basis-full rounded-lg border px-3 py-2 text-sm ${type === 'error' ? 'border-red-500/40 bg-red-950/60 text-red-100' : 'border-emerald-500/40 bg-emerald-950/60 text-emerald-100'}`; }
        function openModal(modal) { modal.classList.remove('hidden'); modal.classList.add('flex'); }
        function closeModals() { [indicatorModal, responsesModal].forEach(modal => { modal.classList.add('hidden'); modal.classList.remove('flex'); }); activeResponsesIndicatorId = null; }
        function resultFor(indicadorId, referencia) { return state.resultados.find(item => Number(item.id_indicador) === Number(indicadorId) && item.referencia === referencia); }
        function comentarioFor(indicadorId) { return state.comentarios.find(item => Number(item.id_indicador) === Number(indicadorId)); }
        function respostasFor(indicadorId) { return state.respostasPreDefinidas.filter(item => Number(item.id_indicador) === Number(indicadorId)); }
        function respostaFor(id) { return state.respostasPreDefinidas.find(item => Number(item.id) === Number(id)); }
        function respostaPonto(id) { const ponto = Number(respostaFor(id)?.ponto); return Number.isFinite(ponto) ? ponto : 0; }
        function hasPredefinedResponses(indicador) { return Number(indicador.respostas_pre_definidas) === 1; }
        function formatDecimalValue(value) {
            const number = Number(value);
            return Number.isFinite(number) ? number : null;
        }
        function displayValue(indicador, resultado) {
            if (!resultado) return '';
            if (hasPredefinedResponses(indicador)) return respostaFor(resultado.id_resposta_definida)?.texto ?? '';
            if (indicador.formato === 'Data') return resultado.data ?? '';
            if (indicador.formato === 'Texto') return resultado.texto ?? '';
            const number = formatDecimalValue(resultado.decimal);
            if (number === null) return resultado.decimal ?? '';
            if (indicador.formato === 'Moeda') return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(number);
            if (indicador.formato === 'Porcentagem') return `${new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(number)}%`;
            if (indicador.formato === 'Número Inteiro') return new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 0 }).format(number);
            return resultado.decimal ?? '';
        }
        function editableValue(indicador, resultado) {
            if (!resultado) return '';
            if (hasPredefinedResponses(indicador)) return resultado.id_resposta_definida ?? '';
            if (indicador.formato === 'Data') return resultado.data ?? '';
            if (indicador.formato === 'Texto') return resultado.texto ?? '';
            return resultado.decimal ?? '';
        }

        function isHidden(tipo, chave) { return state.ocultos.some(item => item.tipo === tipo && String(item.chave) === String(chave)); }
        function visibleColumns() { return ['descricao', 'comentario'].filter(col => state.mostrarOcultos || !isHidden('coluna', col)); }
        function visibleReferences() { return state.referencias.filter(ref => state.mostrarOcultos || !isHidden('coluna', ref)); }
        function scoreForReference(ref, indicadoresVisiveis) {
            let total = 0;
            let score = 0;
            indicadoresVisiveis.filter(hasPredefinedResponses).forEach(indicador => {
                const respostas = respostasFor(indicador.id);
                if (!respostas.length) return;
                const max = Math.max(...respostas.map(item => Number(item.ponto) || 0));
                total += max;
                score += respostaPonto(resultFor(indicador.id, ref)?.id_resposta_definida);
            });
            const percent = total !== 0 ? (score / total) * 100 : 0;
            return { score, total, percent };
        }
        function visibilityButton(tipo, chave, hidden, label, extraClass = '') {
            return `<button type="button" data-visibility-type="${tipo}" data-visibility-key="${escapeHtml(chave)}" data-hidden="${hidden ? 'true' : 'false'}" class="${extraClass} rounded p-1 transition hover:bg-cyan-500/20 hover:text-cyan-300" title="${hidden ? 'Exibir' : 'Ocultar'} ${escapeHtml(label)}" aria-label="${hidden ? 'Exibir' : 'Ocultar'} ${escapeHtml(label)}">${hidden ? eyeClosed : eyeOpen}</button>`;
        }

        function render() {
            toggleHiddenIcon.innerHTML = state.mostrarOcultos ? eyeOpen : eyeClosed;
            toggleHidden.setAttribute('aria-pressed', String(state.mostrarOcultos));
            toggleHidden.title = state.mostrarOcultos ? 'Ocultar novamente linhas e colunas marcadas' : 'Mostrar linhas e colunas ocultas';
            toggleIndicatorColumn.textContent = state.colunaIndicadoresRecolhida ? 'Mostrar indicadores' : 'Recolher indicadores';
            toggleIndicatorColumn.setAttribute('aria-pressed', String(state.colunaIndicadoresRecolhida));
            toggleIndicatorColumn.title = state.colunaIndicadoresRecolhida ? 'Mostrar coluna de indicadores' : 'Recolher coluna de indicadores';
            const indicatorColumnClass = `sticky left-0 z-40 min-w-48 max-w-56 whitespace-normal break-words bg-slate-950 px-4 py-3 ${state.colunaIndicadoresRecolhida ? 'max-sm:hidden' : ''}`;
            const indicatorBodyColumnClass = `group relative sticky left-0 z-20 min-w-48 max-w-56 cursor-cell whitespace-normal break-words bg-slate-900 px-4 py-3 pr-9 font-medium text-white hover:bg-cyan-500/10 ${state.colunaIndicadoresRecolhida ? 'max-sm:hidden' : ''}`;
            const scoreColumnClass = `sticky left-0 z-20 min-w-48 max-w-56 whitespace-normal break-words bg-slate-950 px-4 py-2 text-cyan-200 ${state.colunaIndicadoresRecolhida ? 'max-sm:hidden' : ''}`;
            const referenciasVisiveis = visibleReferences();
            const colunasTextoVisiveis = visibleColumns();
            const textoHeaders = colunasTextoVisiveis.map(col => { const label = col === 'descricao' ? 'Descrição' : 'Comentário'; const hidden = isHidden('coluna', col); return `<th class="min-w-64 px-4 py-3 ${hidden ? 'bg-slate-900/80 text-slate-500' : ''}"><div class="flex items-center gap-1">${label}${visibilityButton('coluna', col, hidden, `coluna ${label}`)}</div></th>`; }).join('');
            head.innerHTML = `<tr><th class="${indicatorColumnClass}">Indicador</th>${textoHeaders}${referenciasVisiveis.map(ref => { const hidden = isHidden('coluna', ref); return `<th class="min-w-32 px-4 py-2 text-center ${hidden ? 'bg-slate-900/80 text-slate-500' : ''}"><div class="flex items-center justify-center gap-1">${escapeHtml(ref)}${visibilityButton('coluna', ref, hidden, `coluna ${ref}`)}</div></th>`; }).join('')}</tr>`;
            if (!state.indicadores.length) { body.innerHTML = `<tr><td colspan="${1 + colunasTextoVisiveis.length + referenciasVisiveis.length}" class="px-4 py-10 text-center text-slate-400">Nenhum indicador cadastrado.</td></tr>`; return; }
            const indicadoresVisiveis = state.indicadores.filter(indicador => state.mostrarOcultos || !isHidden('linha', indicador.id));
            const scoreRow = `<tr class="bg-slate-950/80 font-semibold"><td class="${scoreColumnClass}">Pontuação</td>${colunasTextoVisiveis.map(() => '<td class="px-4 py-2 text-slate-500">—</td>').join('')}${referenciasVisiveis.map(ref => { const score = scoreForReference(ref, indicadoresVisiveis.filter(indicador => !isHidden('linha', indicador.id))); const color = score.percent > 50 ? 'bg-emerald-900/70 text-emerald-100' : 'bg-red-900/70 text-red-100'; return `<td class="min-w-32 px-4 py-2 text-center ${color}">${score.score}/${score.total} (${new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 1 }).format(score.percent)}%)</td>`; }).join('')}</tr>`;
            body.innerHTML = scoreRow + indicadoresVisiveis.map(indicador => { const comentario = comentarioFor(indicador.id); const hidden = isHidden('linha', indicador.id); const textoCells = colunasTextoVisiveis.map(col => col === 'descricao' ? `<td data-edit-indicator="${indicador.id}" data-field="descricao" class="min-w-64 cursor-cell px-4 py-3 text-slate-300 hover:bg-cyan-500/10 ${isHidden('coluna', 'descricao') ? 'bg-slate-950/50 opacity-60' : ''}">${escapeHtml(indicador.descricao || '—')}</td>` : `<td data-comment-indicator="${indicador.id}" class="min-w-64 cursor-cell px-4 py-3 text-slate-300 hover:bg-cyan-500/10 ${isHidden('coluna', 'comentario') ? 'bg-slate-950/50 opacity-60' : ''}" title="Clique duas vezes para adicionar ou editar">${escapeHtml(comentario?.comentario || '—')}</td>`).join(''); return `<tr class="${hidden ? 'bg-slate-950/50 opacity-60' : 'hover:bg-slate-800/50'}"><td data-edit-indicator="${indicador.id}" data-field="nome" class="${indicatorBodyColumnClass}">${escapeHtml(indicador.nome)}${visibilityButton('linha', indicador.id, hidden, `linha ${indicador.nome}`, 'absolute right-1 top-1')}</td>${textoCells}${referenciasVisiveis.map(ref => { const resultado = resultFor(indicador.id, ref); const columnHidden = isHidden('coluna', ref); return `<td data-indicador="${indicador.id}" data-referencia="${ref}" data-valor="${escapeHtml(editableValue(indicador, resultado))}" class="min-w-32 cursor-cell whitespace-nowrap px-4 py-3 text-center text-slate-200 hover:bg-cyan-500/10 ${columnHidden ? 'bg-slate-950/50 opacity-60' : ''}">${escapeHtml(displayValue(indicador, resultado) || '—')}</td>`; }).join('')}</tr>`; }).join('');
        }

        async function request(method = 'GET', data = null) { const response = await fetch(apiUrl, { method, headers: { 'Content-Type': 'application/json' }, body: data ? JSON.stringify(data) : null }); const json = await response.json(); if (!response.ok) throw new Error(json.error || 'Erro inesperado.'); return json; }
        async function load() { const data = await request(); empresaTitle.textContent = data.empresa.nome_da_empresa; state.indicadores = data.indicadores; state.referencias = data.referencias; state.resultados = data.resultados; state.comentarios = data.comentarios; state.ocultos = data.ocultos; state.respostasPreDefinidas = data.respostas_pre_definidas || []; render(); }

        quickCopy.addEventListener('click', copyQuickResult);
        quickMultiplier.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); copyQuickResult(); } });
        document.getElementById('add-indicator').addEventListener('click', () => openModal(indicatorModal));
        document.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', closeModals));
        toggleHidden.addEventListener('click', () => { state.mostrarOcultos = !state.mostrarOcultos; render(); });
        toggleIndicatorColumn.addEventListener('click', () => { state.colunaIndicadoresRecolhida = !state.colunaIndicadoresRecolhida; render(); });
        document.getElementById('results-scroll').addEventListener('click', async event => {
            const button = event.target.closest('[data-visibility-type][data-visibility-key]');
            if (!button) return;
            event.preventDefault();
            event.stopPropagation();
            button.disabled = true;
            try {
                await request('POST', { action: 'visibilidade', tipo: button.dataset.visibilityType, chave: button.dataset.visibilityKey, oculto: button.dataset.hidden !== 'true' });
                await load();
            } catch (error) {
                showNotice(error.message, 'error');
                button.disabled = false;
            }
        });

        document.getElementById('indicator-form').addEventListener('submit', async event => { event.preventDefault(); try { await request('POST', { action: 'indicador', nome: document.getElementById('indicator-name').value, descricao: document.getElementById('indicator-description').value, formato: document.getElementById('indicator-format').value, respostas_pre_definidas: document.getElementById('indicator-response-type').value }); closeModals(); event.target.reset(); showNotice('Indicador cadastrado.'); await load(); } catch (error) { showNotice(error.message, 'error'); } });
        function renderResponsesModal(indicador) {
            responsesIndicatorName.textContent = indicador.nome;
            responsesList.innerHTML = respostasFor(indicador.id).map(resposta => `<div class="flex items-center justify-between gap-3 rounded-xl border border-slate-800 bg-slate-950 px-4 py-3"><div><p class="font-semibold text-white">${escapeHtml(resposta.texto)}</p><p class="text-sm text-slate-400">Ponto: ${escapeHtml(resposta.ponto ?? 0)}</p></div><button type="button" data-delete-response="${resposta.id}" class="rounded-lg border border-red-500/50 px-3 py-2 text-sm font-semibold text-red-200 hover:bg-red-950">Remover</button></div>`).join('') || '<p class="rounded-xl border border-slate-800 bg-slate-950 px-4 py-6 text-center text-slate-400">Nenhuma resposta cadastrada.</p>';
        }
        function openResponsesModal(indicador) { activeResponsesIndicatorId = Number(indicador.id); renderResponsesModal(indicador); openModal(responsesModal); }

        function startSelectEdit(cell, value, options, saveCallback) {
            if (cell.querySelector('select')) return;
            const select = document.createElement('select');
            select.className = 'w-full rounded-lg border border-cyan-500 bg-slate-950 px-2 py-1 text-center text-white outline-none';
            select.innerHTML = `<option value="">Selecione</option>${options.map(option => `<option value="${escapeHtml(option.id)}">${escapeHtml(option.texto)}</option>`).join('')}`;
            select.value = value;
            cell.textContent = '';
            cell.appendChild(select);
            select.focus();
            async function save() { try { await saveCallback(select.value); await load(); } catch (error) { showNotice(error.message, 'error'); await load(); } }
            select.addEventListener('blur', save, { once: true });
            select.addEventListener('change', () => select.blur());
        }

        function startCellEdit(cell, value, inputType, saveCallback, align = 'text-center') {
            if (cell.querySelector('input, textarea')) return;
            const input = document.createElement(inputType === 'textarea' ? 'textarea' : 'input');
            if (input.tagName === 'INPUT') input.type = inputType;
            input.value = value;
            input.className = `w-full rounded-lg border border-cyan-500 bg-slate-950 px-2 py-1 ${align} text-white outline-none`;
            cell.textContent = '';
            cell.appendChild(input);
            input.focus();
            async function save() { try { await saveCallback(input.value); await load(); } catch (error) { showNotice(error.message, 'error'); await load(); } }
            input.addEventListener('blur', save, { once: true });
            input.addEventListener('keydown', event => { if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); input.blur(); } });
        }

        body.addEventListener('contextmenu', event => {
            const indicatorCell = event.target.closest('[data-edit-indicator][data-field="nome"]');
            if (!indicatorCell || event.target.closest('[data-visibility-type]')) return;
            const indicador = state.indicadores.find(item => Number(item.id) === Number(indicatorCell.dataset.editIndicator));
            if (!indicador || !hasPredefinedResponses(indicador)) return;
            event.preventDefault();
            openResponsesModal(indicador);
        });

        document.getElementById('responses-form').addEventListener('submit', async event => {
            event.preventDefault();
            try {
                await request('POST', { action: 'resposta_pre_definida_add', id_indicador: activeResponsesIndicatorId, texto: document.getElementById('response-text').value, ponto: document.getElementById('response-point').value });
                event.target.reset();
                await load();
                const indicador = state.indicadores.find(item => Number(item.id) === activeResponsesIndicatorId);
                if (indicador) renderResponsesModal(indicador);
            } catch (error) { showNotice(error.message, 'error'); }
        });
        responsesList.addEventListener('click', async event => {
            const button = event.target.closest('[data-delete-response]');
            if (!button) return;
            try {
                await request('POST', { action: 'resposta_pre_definida_delete', id: button.dataset.deleteResponse });
                await load();
                const indicador = state.indicadores.find(item => Number(item.id) === activeResponsesIndicatorId);
                if (indicador) renderResponsesModal(indicador);
            } catch (error) { showNotice(error.message, 'error'); }
        });

        body.addEventListener('dblclick', async event => {
            if (event.target.closest('[data-visibility-type]')) return;
            const commentCell = event.target.closest('[data-comment-indicator]');
            if (commentCell) {
                const indicadorId = Number(commentCell.dataset.commentIndicator);
                const comentario = comentarioFor(indicadorId);
                startCellEdit(commentCell, comentario?.comentario || '', 'textarea', value => request('POST', { action: 'comentario', id_indicador: indicadorId, comentario: value }), 'text-left');
                return;
            }
            const indicatorCell = event.target.closest('[data-edit-indicator][data-field]');
            if (indicatorCell) {
                const indicador = state.indicadores.find(item => Number(item.id) === Number(indicatorCell.dataset.editIndicator));
                const field = indicatorCell.dataset.field;
                const current = field === 'descricao' ? (indicador.descricao || '') : indicador.nome;
                startCellEdit(indicatorCell, current, field === 'descricao' ? 'textarea' : 'text', value => request('POST', { action: 'indicador_update', id: indicador.id, field, value }), 'text-left');
                return;
            }
            const cell = event.target.closest('[data-indicador][data-referencia]');
            if (!cell) return;
            const indicador = state.indicadores.find(item => Number(item.id) === Number(cell.dataset.indicador));
            const current = cell.dataset.valor || '';
            const saveResultado = value => request('POST', { action: 'resultado', id_indicador: cell.dataset.indicador, referencia: cell.dataset.referencia, valor: value });
            if (hasPredefinedResponses(indicador)) {
                startSelectEdit(cell, current, respostasFor(indicador.id), saveResultado);
                return;
            }
            startCellEdit(cell, current, indicador.formato === 'Data' ? 'date' : 'text', saveResultado);
        });
        load().catch(error => showNotice(error.message, 'error'));
    </script>
</body>
</html>
