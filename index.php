<?php

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/auth/Auth.php';
require_once __DIR__ . '/middleware/RBAC.php';

Auth::start();

$user = Auth::user();
$canUploadFiles = $user && RBAC::can('rag.write');
$canReadFiles = $user && RBAC::can('rag.read');
$canSearchWeb = $user && RBAC::can('tools.web');

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


            <!-- Nova conversa -->

            <button id="newChat" class="new-chat">
                + Nova conversa
            </button>


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
                    <div id="libraryPanel" class="library-panel hidden" aria-label="Biblioteca de ficheiros"></div>
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

        </main>

    </div>


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
    </script>


    <!-- =========================================================
         JAVASCRIPT DA APLICAÇÃO
    ========================================================== -->

    <script src="public/assets/app.js"></script>

</body>

</html>