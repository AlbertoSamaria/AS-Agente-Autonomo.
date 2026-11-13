<?php

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/auth/Auth.php';
require_once __DIR__ . '/middleware/RBAC.php';

Auth::start();

$user = Auth::user();
$isAdmin = $user && ($user['role'] ?? '') === 'admin';
$canUploadFiles = $user && RBAC::can('rag.write');
$canReadFiles = $user && RBAC::can('rag.read');
$canSearchWeb = $user && RBAC::can('tools.web');
$canReadTasks = $user && RBAC::can('tasks.read');
$canWriteTasks = $user && RBAC::can('tasks.write');
$canReadSql = $user && RBAC::can('tools.sql.read');

?>
<!doctype html>
<html lang="pt">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>SA — Agente Autónomo</title>

    <link rel="stylesheet" href="public/assets/app.css">
    <link rel="stylesheet" href="public/assets/features.css">
</head>

<body>

    <!-- =========================================================
         LOGIN
    ========================================================== -->

    <div id="login" class="login <?php echo $user ? 'hidden' : ''; ?>">

        <div class="login-card">

            <div class="welcome-logo">SA</div>

            <h1>SA</h1>

            <p>Agente Autónomo</p>

            <input
                id="email"
                type="email"
                placeholder="Email"
                autocomplete="email"
            >

            <input
                id="password"
                type="password"
                placeholder="Palavra-passe"
                autocomplete="current-password"
            >

            <button id="loginBtn" type="button">
                Entrar
            </button>

            <div id="loginError"></div>

        </div>

    </div>


    <!-- =========================================================
         APLICAÇÃO
    ========================================================== -->

    <div id="app" class="app <?php echo $user ? '' : 'hidden'; ?>">

        <!-- =====================================================
             SIDEBAR
        ====================================================== -->

        <aside class="sidebar">

            <!-- Marca -->

            <div class="brand">

                <div class="logo">
                    SA
                </div>

                <div>
                    <strong>SA</strong>
                    <span>Agente Autónomo</span>
                </div>

            </div>


            <nav class="side-nav" aria-label="Navegação principal">
                <button id="newChat" class="side-nav-item new-chat" type="button" aria-current="page">
                    <span class="side-nav-icon" aria-hidden="true">＋</span>
                    <span>Novo Chat</span>
                </button>
                <?php if ($canReadFiles): ?>
                <button id="navLibrary" class="side-nav-item" type="button" data-panel="library">
                    <span class="side-nav-icon" aria-hidden="true">▤</span>
                    <span>Biblioteca</span>
                </button>
                <?php endif; ?>
                <?php if ($canReadTasks): ?>
                <button id="navProjects" class="side-nav-item" type="button" data-panel="projects">
                    <span class="side-nav-icon" aria-hidden="true">▦</span>
                    <span>Projectos</span>
                </button>
                <?php endif; ?>
                <button id="navPlugins" class="side-nav-item" type="button" data-panel="plugins">
                    <span class="side-nav-icon" aria-hidden="true">⌘</span>
                    <span>Plugins</span>
                </button>
            </nav>

            <section class="conversation-history" aria-labelledby="conversationHistoryTitle">
                <h2 id="conversationHistoryTitle" class="side-title">Conversas recentes</h2>
                <div id="conversationList" class="conversation-list">
                    <p class="conversation-list-status">A carregar conversas…</p>
                </div>
            </section>

            <!-- Capacidades -->

            <div class="side-section">

                <div class="side-title">
                    Capacidades
                </div>

                <div class="cap">
                    🧠 Memória semântica
                </div>

                <div class="cap">
                    🗂️ Planner + dependências
                </div>

                <div class="cap">
                    📚 RAG PDF/DOCX/XLSX
                </div>

                <div class="cap">
                    ⚙️ Scheduler + Worker
                </div>

                <div class="cap">
                    🛡️ RBAC + Aprovações
                </div>

                <div class="cap">
                    🧑‍💼 Painel administrativo de utilizadores e permissões
                </div>

                <div class="cap">
                    🔐 CSRF em endpoints autenticados
                </div>

            </div>


            <!-- Informação do utilizador -->

            <div
                class="side-footer"
                id="userInfo"
            ></div>

        </aside>


        <!-- =====================================================
             CONTEÚDO PRINCIPAL
        ====================================================== -->

        <main class="main">

            <!-- =================================================
                 TOPBAR
            ================================================== -->

            <header class="topbar">

                <div>

                    <button
                        id="menuBtn"
                        class="icon-btn"
                        type="button"
                        aria-label="Abrir menu"
                    >
                        ☰
                    </button>

                    <div>
                        <h1>SA</h1>
                        <p>Agente Autónomo</p>
                    </div>

                </div>


                <!-- Estado da IA -->

                <div class="status">

                    <span id="statusDot"></span>

                    <span id="statusText">
                        A verificar IA…
                    </span>

                </div>

                <?php if ($user): ?>
                    <?php if ($isAdmin): ?>
                        <button
                            id="adminPanelBtn"
                            class="admin-btn"
                            type="button"
                            aria-label="Abrir painel administrativo"
                        >
                            Painel admin
                        </button>
                    <?php endif; ?>
                    <button
                        id="logoutBtn"
                        class="logout-btn"
                        type="button"
                        aria-label="Terminar sessão"
                    >
                        Terminar sessão
                    </button>
                <?php endif; ?>

            </header>


            <!-- =================================================
                 CHAT
            ================================================== -->

            <section id="chat" class="chat">

                <!-- Ecrã inicial -->

                <div class="welcome">

                    <div class="welcome-logo">
                        SA
                    </div>

                    <h2>
                        O que queres que eu faça?
                    </h2>

                    <p>
                        Planeia, executa ferramentas autorizadas,
                        usa memória e valida resultados.
                    </p>


                    <!-- Sugestões -->

                    <div class="suggestions">

                        <button
                            type="button"
                            data-prompt="Cria um plano para organizar o meu trabalho desta semana."
                        >
                            Planear a semana
                        </button>

                        <button
                            type="button"
                            data-prompt="Cria uma tarefa chamada Preparar apresentação do SA com prioridade alta."
                        >
                            Criar tarefa
                        </button>

                        <button
                            type="button"
                            data-prompt="Explica as capacidades atuais do SA."
                        >
                            Ver capacidades
                        </button>

                    </div>

                </div>

            </section>


            <!-- =================================================
                 COMPOSITOR
            ================================================== -->

            <form id="composer" class="composer">

                <textarea
                    id="message"
                    rows="1"
                    placeholder="Escreve uma tarefa para o SA…"
                ></textarea>

                <div class="composer-tools">
                    <?php if ($canUploadFiles): ?>
                    <input
                        id="fileInput"
                        type="file"
                        accept=".pdf,.docx,.xlsx,.txt,.csv"
                        multiple
                        hidden
                    >
                    <?php endif; ?>
                    <div class="action-menu">
                        <button
                            id="actionMenuBtn"
                            class="tool-btn plus-btn"
                            type="button"
                            aria-label="Abrir ações"
                            aria-haspopup="menu"
                            aria-expanded="false"
                            aria-controls="actionMenu"
                        >+</button>
                        <div id="actionMenu" class="action-menu-panel hidden" role="menu" aria-labelledby="actionMenuBtn">
                            <?php if ($canReadFiles): ?>
                            <button class="action-item" type="button" role="menuitem" data-action="library">
                                <span class="action-icon">▤</span>
                                <span>Adicionar da biblioteca</span>
                            </button>
                            <?php endif; ?>
                            <?php if ($canUploadFiles): ?>
                            <button class="action-item" type="button" role="menuitem" data-action="upload">
                                <span class="action-icon">↑</span>
                                <span>Adicionar ficheiros do computador</span>
                            </button>
                            <?php endif; ?>
                            <button id="voiceBtn" class="action-item" type="button" role="menuitem" data-action="voice">
                                <span class="action-icon">🎙</span>
                                <span>Ditado por voz</span>
                            </button>
                            <?php if ($canSearchWeb): ?>
                            <button class="action-item" type="button" role="menuitem" data-action="web-search" aria-pressed="false">
                                <span class="action-icon">⌕</span>
                                <span>Pesquisa na web</span>
                            </button>
                            <?php endif; ?>
                            <div class="action-divider"></div>
                            <button class="action-item unavailable" type="button" role="menuitem" disabled title="Esta funcionalidade ainda não está configurada">
                                <span class="action-icon">▧</span>
                                <span>Criar imagem <small>Em breve</small></span>
                            </button>
                            <button class="action-item unavailable" type="button" role="menuitem" disabled title="Esta funcionalidade ainda não está configurada">
                                <span class="action-icon">⌕</span>
                                <span>Pesquisa profunda <small>Em breve</small></span>
                            </button>
                            <button class="action-item unavailable" type="button" role="menuitem" disabled title="Esta funcionalidade ainda não está configurada">
                                <span class="action-icon">✎</span>
                                <span>Esboço <small>Em breve</small></span>
                            </button>
                            <button class="action-item unavailable" type="button" role="menuitem" disabled title="Esta funcionalidade ainda não está configurada">
                                <span class="action-icon">⌘</span>
                                <span>GitHub <small>Em breve</small></span>
                            </button>
                            <button class="action-item unavailable" type="button" role="menuitem" disabled title="Esta funcionalidade ainda não está configurada">
                                <span class="action-icon">⊞</span>
                                <span>Open Platform <small>Em breve</small></span>
                            </button>
                        </div>
                    </div>
                    <div id="selectedDocuments" class="selected-documents" aria-live="polite"></div>
                    <span id="uploadStatus" role="status" aria-live="polite"></span>
                </div>

                <button
                    id="sendBtn"
                    type="submit"
                >
                    Enviar
                </button>

            </form>


            <!-- Atalho -->

            <div class="hint">
                Enter envia · Shift+Enter nova linha
            </div>

            <div id="navigationPanel" class="navigation-panel hidden" role="dialog" aria-modal="true" aria-labelledby="navigationPanelTitle">
                <div class="navigation-panel-card">
                    <header class="navigation-panel-header">
                        <h2 id="navigationPanelTitle"></h2>
                        <button id="closeNavigationPanel" class="close-navigation-panel" type="button" aria-label="Fechar painel">×</button>
                    </header>
                    <div id="navigationPanelContent" class="navigation-panel-content">
                        <?php if ($canReadFiles): ?>
                        <div id="libraryPanel" class="library-panel hidden" aria-label="Biblioteca de ficheiros"></div>
                        <?php endif; ?>
                        <div id="projectsPanel" class="navigation-panel-view hidden">
                            <div class="navigation-empty-icon" aria-hidden="true">▦</div>
                            <h3>Projectos</h3>
                            <p>A área de projectos ainda não está disponível. Já podes criar planos e tarefas diretamente no chat.</p>
                            <?php if ($canWriteTasks): ?>
                            <button class="navigation-primary" type="button" data-prompt="Cria um plano para organizar o meu próximo projecto.">
                                Criar um plano no chat
                            </button>
                            <?php endif; ?>
                        </div>
                        <div id="pluginsPanel" class="navigation-panel-view hidden">
                            <p class="navigation-panel-description">Ferramentas integradas no agente. A disponibilidade depende das permissões da tua conta.</p>
                            <ul class="plugin-list">
                                <li><span>Gestão de tarefas</span><small><?php echo $canReadTasks ? ($canWriteTasks ? 'Disponível' : 'Só leitura') : 'Sem permissão'; ?></small></li>
                                <li><span>Pesquisa na web</span><small><?php echo $canSearchWeb ? 'Disponível' : 'Sem permissão'; ?></small></li>
                                <li><span>Ficheiros e biblioteca</span><small><?php echo $canReadFiles ? ($canUploadFiles ? 'Disponível' : 'Só leitura') : 'Sem permissão'; ?></small></li>
                                <li><span>Consultas SQL de leitura</span><small><?php echo $canReadSql ? 'Disponível mediante aprovação' : 'Sem permissão'; ?></small></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

        </main>

    </div>

    <?php if ($isAdmin): ?>
    <div id="adminPanel" class="admin-panel hidden" aria-live="polite">
        <div class="admin-panel-header">
            <div>
                <p class="admin-panel-kicker">Controlo</p>
                <h2>Painel administrativo</h2>
            </div>
            <button id="closeAdminPanel" class="close-admin-panel" type="button" aria-label="Fechar painel administrativo">×</button>
        </div>

        <div class="admin-panel-grid">
            <section class="admin-card">
                <div class="admin-card-header">
                    <h3>Gestão de utilizadores</h3>
                </div>

                <form id="adminCreateUserForm" class="admin-form">
                    <div class="admin-form-grid">
                        <label>
                            Nome
                            <input type="text" name="name" placeholder="Nome completo" required>
                        </label>
                        <label>
                            Email
                            <input type="email" name="email" placeholder="email@exemplo.pt" required>
                        </label>
                        <label>
                            Palavra-passe
                            <input type="password" name="password" placeholder="Mínimo 8 caracteres" required>
                        </label>
                        <label>
                            Role
                            <select name="role">
                                <option value="viewer">viewer</option>
                                <option value="operator">operator</option>
                                <option value="manager">manager</option>
                                <option value="admin">admin</option>
                            </select>
                        </label>
                    </div>
                    <button type="submit" class="primary-btn">Criar utilizador</button>
                </form>

                <div class="admin-toolbar">
                    <input id="adminUserSearch" type="search" placeholder="Pesquisa por nome ou email" aria-label="Pesquisar utilizadores">
                    <select id="adminUserStatusFilter" aria-label="Filtrar utilizadores por estado">
                        <option value="all">Todos</option>
                        <option value="active">Ativos</option>
                        <option value="inactive">Inativos</option>
                    </select>
                </div>
                <div id="adminUsersTable" class="admin-table-wrap"></div>
            </section>

            <section class="admin-card">
                <div class="admin-card-header">
                    <h3>Permissões por ação</h3>
                </div>
                <div class="admin-toolbar admin-toolbar-compact">
                    <input id="permissionSearch" type="search" placeholder="Filtrar permissões" aria-label="Filtrar permissões">
                    <select id="permissionUserSelect"></select>
                </div>
                <div id="permissionList" class="permission-list"></div>
            </section>

            <section class="admin-card">
                <div class="admin-card-header">
                    <h3>Histórico de aprovações</h3>
                </div>
                <div class="admin-toolbar admin-toolbar-compact">
                    <select id="approvalStatusFilter" aria-label="Filtrar aprovações por estado">
                        <option value="all">Todas</option>
                        <option value="pending">Pendentes</option>
                        <option value="approved">Aprovadas</option>
                        <option value="rejected">Rejeitadas</option>
                    </select>
                </div>
                <div id="adminApprovals" class="admin-approvals"></div>
            </section>

            <section class="admin-card admin-card-wide">
                <div class="admin-card-header">
                    <h3>Auditoria detalhada</h3>
                </div>
                <div class="admin-toolbar admin-toolbar-compact">
                    <input id="auditSearch" type="search" placeholder="Pesquisa por ação, utilizador ou alvo" aria-label="Pesquisar auditoria">
                    <select id="auditStatusFilter" aria-label="Filtrar auditoria por estado">
                        <option value="all">Todos</option>
                        <option value="success">Sucesso</option>
                        <option value="failed">Falhas</option>
                        <option value="warning">Avisos</option>
                    </select>
                </div>
                <div id="adminAuditList" class="audit-list"></div>
            </section>
        </div>
    </div>
    <?php endif; ?>

    <!-- =========================================================
         DADOS DO UTILIZADOR PARA JAVASCRIPT
    ========================================================== -->

    <script>
        window.SA_USER = <?php
            echo json_encode(
                $user,
                JSON_UNESCAPED_UNICODE
            );
        ?>;
        window.SA_CSRF = <?php echo json_encode($user ? Auth::csrfToken() : null, JSON_UNESCAPED_UNICODE); ?>;
    </script>


    <!-- =========================================================
         JAVASCRIPT DA APLICAÇÃO
    ========================================================== -->

    <script src="public/assets/app.js"></script>

</body>

</html>