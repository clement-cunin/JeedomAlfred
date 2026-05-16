<?php
if (!isConnect()) {
    throw new Exception('{{401 - Unauthorized access}}');
}

$_chatUrl      = network::getNetworkAccess('external', 'proto:ip:port:comp') . '/plugins/alfred/chat/index.php';
$_isConfigured = alfred::getApiKey() !== '';
$_userHash     = $_SESSION['user']->getHash();
?>
<link rel="stylesheet" href="plugins/alfred/desktop/css/alfred.css">

<div id="alfred-app">

    <!-- Sidebar: conversation list -->
    <div id="alfred-sidebar">
        <div id="alfred-sidebar-header">
            <button id="alfred-new-chat" class="btn btn-sm btn-default" title="{{New conversation}}">
                <i class="fas fa-plus"></i> <span class="alfred-label">{{New conversation}}</span>
            </button>
        </div>
        <div id="alfred-conversations-label">{{Conversations}}</div>
        <div id="alfred-conversations"></div>
    </div>

    <!-- Toggle sidebar on mobile -->
    <button id="alfred-sidebar-toggle" title="Menu">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Main chat area -->
    <div id="alfred-main">

        <!-- Message area -->
        <div id="alfred-messages">
            <div id="alfred-welcome">
                <div class="alfred-welcome-icon">
                    <i class="fas fa-robot"></i>
                </div>
                <h2>{{Hello, I'm Alfred.}}</h2>
                <?php if ($_isConfigured): ?>
                <p>{{Ask me anything about your home automation system.}}</p>
                <?php else: ?>
                <p class="text-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    {{Configure Alfred in plugin settings to get started.}}
                </p>
                <?php endif; ?>
                <a href="<?php echo htmlspecialchars($_chatUrl); ?>" target="_blank"
                   class="btn btn-default btn-sm" style="margin-top:16px">
                    <i class="fas fa-external-link-alt"></i> {{Open standalone chat}}
                </a>
            </div>
        </div>

        <!-- Quota bar (hidden until first response with quota data) -->
        <div id="alfred-quota-bar" style="display:none">
            <div id="alfred-quota-fill"></div>
            <span id="alfred-quota-label"></span>
        </div>

        <!-- Input bar -->
        <div id="alfred-input-bar">
            <textarea id="alfred-input"
                      placeholder="{{Type a message…}}"
                      rows="1"
                      <?php echo !$_isConfigured ? 'disabled' : ''; ?>></textarea>
            <div id="alfred-tts-wrap">
                <button id="alfred-tts" title="{{Text-to-speech}}"
                        <?php echo !$_isConfigured ? 'disabled' : ''; ?>>
                    <i class="fas fa-volume-up"></i>
                </button>
                <button id="alfred-tts-settings" title="{{TTS settings}}"
                        <?php echo !$_isConfigured ? 'disabled' : ''; ?>>
                    <i class="fas fa-sliders-h"></i>
                </button>
            </div>
            <button id="alfred-mic-autosend" title="{{Auto-send: OFF}}"
                    <?php echo !$_isConfigured ? 'disabled' : ''; ?>>
                <i class="fas fa-bolt"></i>
            </button>
            <button id="alfred-mic" title="{{Voice input}}"
                    <?php echo !$_isConfigured ? 'disabled' : ''; ?>>
                <i class="fas fa-microphone"></i>
            </button>
            <button id="alfred-send" title="{{Send}}"
                    <?php echo !$_isConfigured ? 'disabled' : ''; ?>>
                <i class="fas fa-paper-plane"></i>
            </button>
        </div>

    </div>
</div>

<script>
var alfred_config = {
    isConfigured:  <?php echo $_isConfigured ? 'true' : 'false'; ?>,
    userHash:      "<?php echo htmlspecialchars($_userHash, ENT_QUOTES); ?>",
    isAdmin:       <?php echo ($_SESSION['user']->getProfils() === 'admin') ? 'true' : 'false'; ?>,
    showQuotaBar:  <?php echo alfred::getShowQuotaBar() ? 'true' : 'false'; ?>,
    i18n: {
        hello: "{{Hello, I'm Alfred.}}",
        ask:   "{{Ask me anything about your home automation system.}}"
    }
};
// Persist auth info so the standalone PWA can work without a PHP session
localStorage.setItem('alfred_user_hash',      alfred_config.userHash);
localStorage.setItem('alfred_is_configured',  alfred_config.isConfigured ? '1' : '0');
localStorage.setItem('alfred_is_admin',       alfred_config.isAdmin ? '1' : '0');
localStorage.setItem('alfred_show_quota_bar', alfred_config.showQuotaBar ? '1' : '0');
// Auto-redirect back to chat if login was initiated from there
if (localStorage.getItem('alfred_return_to_chat') === '1') {
    localStorage.removeItem('alfred_return_to_chat');
    window.location.replace('<?php echo htmlspecialchars($_chatUrl, ENT_QUOTES); ?>');
}
</script>
<script>
<?php readfile(__DIR__ . '/../js/alfred.js'); ?>
</script>
