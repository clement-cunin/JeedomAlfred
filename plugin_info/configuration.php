<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Unauthorized access}}');
}

// Auto-detect JeedomMCP internal URL for placeholder
$_mcpUrlPlaceholder = network::getNetworkAccess('internal', 'proto:ip:port:comp') . '/plugins/jeedomMCP/api/mcp.php';

$_providers = [
    'anthropic' => 'Anthropic (Claude)',
    'openai'    => 'OpenAI (GPT)',
    'gemini'    => 'Google (Gemini)',
];

$_models = [
    'anthropic' => [
        'claude-sonnet-4-6'        => 'Claude Sonnet 4.6',
        'claude-opus-4-6'          => 'Claude Opus 4.6',
        'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5',
    ],
    'openai' => [
        'gpt-4o'      => 'GPT-4o',
        'gpt-4o-mini' => 'GPT-4o mini',
        'gpt-4-turbo' => 'GPT-4 Turbo',
    ],
    'gemini' => [
        'gemini-2.0-flash'              => 'Gemini 2.0 Flash',
        'gemini-2.5-pro-preview-03-25'  => 'Gemini 2.5 Pro',
        'gemini-1.5-pro'                => 'Gemini 1.5 Pro',
    ],
];
?>

<form class="form-horizontal">
    <fieldset>

        <!-- ================================================================ -->
        <!-- LLM Provider -->
        <!-- ================================================================ -->
        <legend><i class="fas fa-robot"></i> {{AI provider}}</legend>

        <div class="form-group">
            <label class="col-sm-4 control-label">{{Provider}}</label>
            <div class="col-sm-4">
                <select id="alfred_provider" class="configKey form-control" data-l1key="provider">
                    <?php foreach ($_providers as $_pid => $_plabel): ?>
                    <option value="<?php echo $_pid; ?>"><?php echo $_plabel; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <span class="help-block col-sm-4">{{Select the AI provider to use.}}</span>
        </div>

        <!-- Anthropic -->
        <div class="alfred-provider-section" data-provider="anthropic">
            <div class="form-group">
                <label class="col-sm-4 control-label">{{Anthropic API key}}</label>
                <div class="col-sm-4">
                    <input type="password" class="configKey form-control" data-l1key="anthropic_api_key"
                           placeholder="sk-ant-..." autocomplete="new-password" />
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-4 control-label">{{Model}}</label>
                <div class="col-sm-4">
                    <select class="configKey form-control" data-l1key="anthropic_model">
                        <?php foreach ($_models['anthropic'] as $_mid => $_mlabel): ?>
                        <option value="<?php echo $_mid; ?>"><?php echo $_mlabel; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <span class="help-block col-sm-4">{{Model used for this provider.}}</span>
            </div>
        </div>

        <!-- OpenAI -->
        <div class="alfred-provider-section" data-provider="openai">
            <div class="form-group">
                <label class="col-sm-4 control-label">{{OpenAI API key}}</label>
                <div class="col-sm-4">
                    <input type="password" class="configKey form-control" data-l1key="openai_api_key"
                           placeholder="sk-..." autocomplete="new-password" />
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-4 control-label">{{Model}}</label>
                <div class="col-sm-4">
                    <select class="configKey form-control" data-l1key="openai_model">
                        <?php foreach ($_models['openai'] as $_mid => $_mlabel): ?>
                        <option value="<?php echo $_mid; ?>"><?php echo $_mlabel; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <span class="help-block col-sm-4">{{Model used for this provider.}}</span>
            </div>
        </div>

        <!-- Gemini -->
        <div class="alfred-provider-section" data-provider="gemini">
            <div class="form-group">
                <label class="col-sm-4 control-label">{{Gemini API key}}</label>
                <div class="col-sm-4">
                    <input type="password" class="configKey form-control" data-l1key="gemini_api_key"
                           placeholder="AIza..." autocomplete="new-password" />
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-4 control-label">{{Model}}</label>
                <div class="col-sm-4">
                    <select class="configKey form-control" data-l1key="gemini_model">
                        <?php foreach ($_models['gemini'] as $_mid => $_mlabel): ?>
                        <option value="<?php echo $_mid; ?>"><?php echo $_mlabel; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <span class="help-block col-sm-4">{{Model used for this provider.}}</span>
            </div>
        </div>

        <!-- ================================================================ -->
        <!-- JeedomMCP -->
        <!-- ================================================================ -->
        <legend><i class="fas fa-plug"></i> JeedomMCP</legend>

        <div class="form-group">
            <label class="col-sm-4 control-label">{{JeedomMCP URL}}</label>
            <div class="col-sm-5">
                <input type="text" class="configKey form-control" data-l1key="mcp_url"
                       placeholder="<?php echo htmlspecialchars($_mcpUrlPlaceholder); ?>" />
            </div>
            <span class="help-block col-sm-3">{{The URL of your JeedomMCP endpoint (internal network recommended).}}</span>
        </div>

        <div class="form-group">
            <label class="col-sm-4 control-label">{{JeedomMCP API key}}</label>
            <div class="col-sm-4">
                <input type="password" class="configKey form-control" data-l1key="mcp_api_key"
                       autocomplete="new-password" />
            </div>
        </div>

        <!-- ================================================================ -->
        <!-- Agent settings -->
        <!-- ================================================================ -->
        <legend><i class="fas fa-cogs"></i> {{Settings}}</legend>

        <div class="form-group">
            <label class="col-sm-4 control-label">{{System prompt}}</label>
            <div class="col-sm-6">
                <textarea class="configKey form-control" data-l1key="system_prompt" rows="4"
                          style="font-family: monospace; font-size: 12px;"></textarea>
            </div>
        </div>

        <div class="form-group">
            <label class="col-sm-4 control-label">{{Max agent iterations}}</label>
            <div class="col-sm-2">
                <input type="number" class="configKey form-control" data-l1key="max_iterations"
                       min="1" max="30" />
            </div>
            <span class="help-block col-sm-6">{{Maximum number of tool-call iterations before the agent stops.}}</span>
        </div>

    </fieldset>
</form>

<script>
function alfredShowProvider(provider) {
    $('.alfred-provider-section').hide();
    $('.alfred-provider-section[data-provider="' + provider + '"]').show();
}

// Wait for Jeedom to populate the select, then apply
var _alfredProviderPoll = setInterval(function () {
    var val = $('#alfred_provider').val();
    if (val) {
        alfredShowProvider(val);
        clearInterval(_alfredProviderPoll);
    }
}, 100);

$('#alfred_provider').on('change', function () {
    alfredShowProvider($(this).val());
});
</script>
