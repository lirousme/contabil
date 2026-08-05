<?php

require __DIR__ . '/../src/config.php';

$pdo = db();
$action = $_POST['action'] ?? $_GET['action'] ?? 'index';
$error = null;
$editingCompany = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim($_POST['nome_da_empresa'] ?? '');
    $codCvm = trim($_POST['cod_cvm'] ?? '');
    $codCvm = $codCvm === '' ? null : (int) $codCvm;

    if ($action !== 'delete' && $nome === '') {
        $error = 'Informe o nome da empresa.';
    } elseif ($action === 'create') {
        $stmt = $pdo->prepare('INSERT INTO empresas (nome_da_empresa, cod_cvm) VALUES (:nome_da_empresa, :cod_cvm)');
        $stmt->execute(['nome_da_empresa' => $nome, 'cod_cvm' => $codCvm]);
        header('Location: /');
        exit;
    } elseif ($action === 'update') {
        $stmt = $pdo->prepare('UPDATE empresas SET nome_da_empresa = :nome_da_empresa, cod_cvm = :cod_cvm WHERE id = :id');
        $stmt->execute(['nome_da_empresa' => $nome, 'cod_cvm' => $codCvm, 'id' => (int) $_POST['id']]);
        header('Location: /');
        exit;
    } elseif ($action === 'delete') {
        $stmt = $pdo->prepare('DELETE FROM empresas WHERE id = :id');
        $stmt->execute(['id' => (int) $_POST['id']]);
        header('Location: /');
        exit;
    }
}

if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $pdo->prepare('SELECT id, nome_da_empresa, cod_cvm FROM empresas WHERE id = :id');
    $stmt->execute(['id' => (int) $_GET['id']]);
    $editingCompany = $stmt->fetch();
}

$companies = $pdo->query('SELECT id, nome_da_empresa, cod_cvm FROM empresas ORDER BY id DESC')->fetchAll();

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="pt-BR" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cadastro de Empresas</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class' };
    </script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
    <main class="mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 py-10 sm:px-6 lg:px-8">
        <section class="rounded-3xl border border-slate-800 bg-slate-900/80 p-6 shadow-2xl shadow-slate-950/40">
            <div class="mb-6">
                <p class="text-sm font-semibold uppercase tracking-[0.3em] text-cyan-400">Empresas</p>
                <h1 class="mt-2 text-3xl font-bold tracking-tight text-white">Cadastro de empresas</h1>
                <p class="mt-2 text-slate-400">Cadastre, edite e exclua empresas com tema escuro por padrão.</p>
            </div>

            <?php if ($error): ?>
                <div class="mb-5 rounded-2xl border border-red-500/40 bg-red-950/60 px-4 py-3 text-sm text-red-100">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" class="grid gap-4 rounded-2xl border border-slate-800 bg-slate-950/70 p-5 md:grid-cols-[1fr_220px_auto] md:items-end">
                <input type="hidden" name="action" value="<?= $editingCompany ? 'update' : 'create' ?>">
                <?php if ($editingCompany): ?>
                    <input type="hidden" name="id" value="<?= (int) $editingCompany['id'] ?>">
                <?php endif; ?>

                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-slate-300">Nome da empresa</span>
                    <input name="nome_da_empresa" value="<?= e($editingCompany['nome_da_empresa'] ?? '') ?>" required class="w-full rounded-xl border border-slate-700 bg-slate-900 px-4 py-3 text-white outline-none ring-cyan-500 transition placeholder:text-slate-500 focus:border-cyan-400 focus:ring-2" placeholder="Ex.: Empresa S.A.">
                </label>

                <label class="block">
                    <span class="mb-2 block text-sm font-medium text-slate-300">Código CVM</span>
                    <input name="cod_cvm" type="number" value="<?= e(isset($editingCompany['cod_cvm']) ? (string) $editingCompany['cod_cvm'] : '') ?>" class="w-full rounded-xl border border-slate-700 bg-slate-900 px-4 py-3 text-white outline-none ring-cyan-500 transition placeholder:text-slate-500 focus:border-cyan-400 focus:ring-2" placeholder="Opcional">
                </label>

                <div class="flex gap-3">
                    <button class="rounded-xl bg-cyan-500 px-5 py-3 font-semibold text-slate-950 transition hover:bg-cyan-400">
                        <?= $editingCompany ? 'Salvar' : 'Cadastrar' ?>
                    </button>
                    <?php if ($editingCompany): ?>
                        <a href="/" class="rounded-xl border border-slate-700 px-5 py-3 font-semibold text-slate-200 transition hover:bg-slate-800">Cancelar</a>
                    <?php endif; ?>
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
                    <tbody class="divide-y divide-slate-800 text-sm">
                        <?php foreach ($companies as $company): ?>
                            <tr class="hover:bg-slate-800/50">
                                <td class="px-6 py-4 text-slate-400"><?= (int) $company['id'] ?></td>
                                <td class="px-6 py-4 font-medium text-white"><?= e($company['nome_da_empresa']) ?></td>
                                <td class="px-6 py-4 text-slate-300"><?= e($company['cod_cvm'] ?? '—') ?></td>
                                <td class="px-6 py-4">
                                    <div class="flex justify-end gap-3">
                                        <a href="/?action=edit&id=<?= (int) $company['id'] ?>" class="rounded-lg border border-cyan-500/40 px-3 py-2 text-cyan-300 transition hover:bg-cyan-500/10">Editar</a>
                                        <form method="post" onsubmit="return confirm('Excluir esta empresa?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $company['id'] ?>">
                                            <button class="rounded-lg border border-red-500/40 px-3 py-2 text-red-300 transition hover:bg-red-500/10">Excluir</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (!$companies): ?>
                            <tr>
                                <td colspan="4" class="px-6 py-10 text-center text-slate-400">Nenhuma empresa cadastrada ainda.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
