<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Unauthorized access}}');
}

include_file('core', 'alfredMigration', 'class', 'alfred');
$_schemaVersion       = alfredMigration::getVersion();
$_schemaTargetVersion = alfredMigration::getTargetVersion();

// Auto-detect JeedomMCP settings (used for button + placeholder)
$_mcpAutoUrl    = network::getNetworkAccess('internal', 'proto:ip:port:comp') . '/plugins/jeedomMCP/api/mcp.php';
$_mcpAutoApiKey = config::byKey('mcpApiKey', 'jeedomMCP');

// JS i18n strings — always via json_encode to survive apostrophes in translations
$_js_i18n = [
    'mcp_autodetected' => __('JeedomMCP settings auto-detected.', __FILE__),
    'enter_api_key'    => __('Enter the API key first.', __FILE__),
    'loading'          => __('Loading…', __FILE__),
    'error'            => __('Error', __FILE__),
    'up_to_date'       => __('Up to date', __FILE__),
];

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
        'gemini-1.5-pro'   => 'Gemini 1.5 Pro',
        'gemini-1.5-flash' => 'Gemini 1.5 Flash',
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
            <div class="col-sm-offset-4 col-sm-8" style="margin-bottom:8px">
                <button type="button" class="btn btn-default btn-sm" id="bt_alfred_test_llm">
                    <i class="fas fa-plug"></i> {{Test LLM connection}}
                </button>
                <span id="alfred_test_llm_result" style="margin-left:10px;font-size:13px"></span>
            </div>
        </div>

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
                    <input type="password" class="configKey form-control alfred-api-key" data-l1key="anthropic_api_key"
                           placeholder="sk-ant-..." autocomplete="new-password" />
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-4 control-label">{{Model}}</label>
                <div class="col-sm-4">
                    <select class="configKey form-control alfred-model-select" data-l1key="anthropic_model">
                        <?php $_saved = config::byKey('anthropic_model', 'alfred'); ?>
                        <option value="<?php echo htmlspecialchars($_saved); ?>"><?php echo htmlspecialchars($_saved ?: '—'); ?></option>
                    </select>
                </div>
            </div>
        </div>

        <!-- OpenAI -->
        <div class="alfred-provider-section" data-provider="openai">
            <div class="form-group">
                <label class="col-sm-4 control-label">{{OpenAI API key}}</label>
                <div class="col-sm-4">
                    <input type="password" class="configKey form-control alfred-api-key" data-l1key="openai_api_key"
                           placeholder="sk-..." autocomplete="new-password" />
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-4 control-label">{{Model}}</label>
                <div class="col-sm-4">
                    <select class="configKey form-control alfred-model-select" data-l1key="openai_model">
                        <?php $_saved = config::byKey('openai_model', 'alfred'); ?>
                        <option value="<?php echo htmlspecialchars($_saved); ?>"><?php echo htmlspecialchars($_saved ?: '—'); ?></option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Gemini -->
        <div class="alfred-provider-section" data-provider="gemini">
            <div class="form-group">
                <label class="col-sm-4 control-label">{{Gemini API key}}</label>
                <div class="col-sm-4">
                    <input type="password" class="configKey form-control alfred-api-key" data-l1key="gemini_api_key"
                           placeholder="AIza..." autocomplete="new-password" />
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-4 control-label">{{Model}}</label>
                <div class="col-sm-4">
                    <select class="configKey form-control alfred-model-select" data-l1key="gemini_model">
                        <?php $_saved = config::byKey('gemini_model', 'alfred'); ?>
                        <option value="<?php echo htmlspecialchars($_saved); ?>"><?php echo htmlspecialchars($_saved ?: '—'); ?></option>
                    </select>
                </div>
            </div>
        </div>

        <!-- ================================================================ -->
        <!-- JeedomMCP -->
        <!-- ================================================================ -->
        <legend><i class="fas fa-plug"></i> JeedomMCP</legend>

        <div class="form-group">
            <div class="col-sm-offset-4 col-sm-8" style="margin-bottom:8px">
                <button type="button" class="btn btn-default btn-sm" id="bt_alfred_autodetect_mcp">
                    <i class="fas fa-magic"></i> {{Auto-detect from JeedomMCP plugin}}
                </button>
            </div>
        </div>

        <div class="form-group">
            <label class="col-sm-4 control-label">{{JeedomMCP URL}}</label>
            <div class="col-sm-5">
                <input type="text" class="configKey form-control" data-l1key="mcp_url"
                       placeholder="<?php echo htmlspecialchars($_mcpAutoUrl); ?>" />
            </div>
            <span class="help-block col-sm-3">{{Internal network URL recommended.}}</span>
        </div>

        <div class="form-group">
            <label class="col-sm-4 control-label">{{JeedomMCP API key}}</label>
            <div class="col-sm-4">
                <input type="password" class="configKey form-control" data-l1key="mcp_api_key"
                       autocomplete="new-password" />
            </div>
        </div>

        <!-- ================================================================ -->
        <!-- Database -->
        <!-- ================================================================ -->
        <legend><i class="fas fa-database"></i> {{Database}}</legend>

        <div class="form-group">
            <label class="col-sm-4 control-label">{{Schema version}}</label>
            <div class="col-sm-4" style="padding-top:7px">
                <?php if ($_schemaVersion >= $_schemaTargetVersion): ?>
                <span style="color:#3c763d">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $_schemaVersion; ?> / <?php echo $_schemaTargetVersion; ?> — {{Up to date}}
                </span>
                <?php else: ?>
                <span style="color:#8a6d3b">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo $_schemaVersion; ?> / <?php echo $_schemaTargetVersion; ?> — {{Outdated}}
                </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-group">
            <div class="col-sm-offset-4 col-sm-8">
                <button type="button" class="btn btn-default btn-sm" id="bt_alfred_repair_db">
                    <i class="fas fa-wrench"></i> {{Repair database}}
                </button>
                <span id="alfred_repair_db_result" style="margin-left:10px;font-size:13px"></span>
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
                       min="1" max="30" placeholder="10" />
            </div>
            <span class="help-block col-sm-6">{{Maximum number of tool-call iterations before the agent stops.}}</span>
        </div>

    </fieldset>
</form>

<script>
var _alfredMcpAutoUrl    = <?php echo json_encode($_mcpAutoUrl); ?>;
var _alfredMcpAutoApiKey = <?php echo json_encode($_mcpAutoApiKey); ?>;
var _alfredI18n          = <?php echo json_encode($_js_i18n); ?>;
var _alfredSchemaTarget  = <?php echo (int) $_schemaTargetVersion; ?>;

$('#bt_alfred_repair_db').on('click', function () {
    var $btn    = $(this);
    var $result = $('#alfred_repair_db_result');

    $btn.prop('disabled', true);
    $result.html('<i class="fas fa-spinner fa-spin"></i>');

    $.ajax({
        type: 'POST',
        url: 'plugins/alfred/core/ajax/alfred.ajax.php',
        data: { action: 'runMigrations' },
        dataType: 'json',
        success: function (data) {
            if (data.state === 'ok') {
                var v = data.result;
                $result.html('<span style="color:#3c763d"><i class="fas fa-check"></i> ' + v + ' / ' + _alfredSchemaTarget + ' — ' + _alfredI18n.up_to_date + '</span>');
            } else {
                $result.html('<span style="color:#a94442"><i class="fas fa-times"></i> ' + (data.result || data.state) + '</span>');
            }
        },
        error: function (jqXHR) {
            $result.html('<span style="color:#a94442"><i class="fas fa-times"></i> ' + jqXHR.responseText + '</span>');
        },
        complete: function () {
            $btn.prop('disabled', false);
        }
    });
});

$('#bt_alfred_autodetect_mcp').on('click', function () {
    $('[data-l1key="mcp_url"]').val(_alfredMcpAutoUrl);
    $('[data-l1key="mcp_api_key"]').val(_alfredMcpAutoApiKey);
    $.fn.showAlert({ message: _alfredI18n.mcp_autodetected, level: 'success' });
});

function alfredShowProvider(provider) {
    $('.alfred-provider-section').hide();
    $('.alfred-provider-section[data-provider="' + provider + '"]').show();
}

// Wait until Jeedom has loaded config values, then show the right provider section
var _alfredPollCount = 0;
var _alfredProviderPoll = setInterval(function () {
    _alfredPollCount++;
    var hasKey = !!($('[data-l1key="anthropic_api_key"]').val()
                  || $('[data-l1key="openai_api_key"]').val()
                  || $('[data-l1key="gemini_api_key"]').val());
    if (hasKey || _alfredPollCount >= 15) {
        clearInterval(_alfredProviderPoll);
        alfredShowProvider($('#alfred_provider').val());
    }
}, 100);

$('#alfred_provider').on('change', function () {
    alfredShowProvider($(this).val());
});

// ---- Lazy model loading ----
// Triggered on first mousedown on the select (before dropdown opens).
// Shows a loading placeholder, fetches, then restores saved value.

var _alfredModelsLoaded = {};

function alfredLoadModels($section) {
    var provider = $section.data('provider');
    if (_alfredModelsLoaded[provider]) return;
    _alfredModelsLoaded[provider] = true;

    var $select  = $section.find('.alfred-model-select');
    var apiKey   = $section.find('.alfred-api-key').val().trim();
    var savedVal = $select.val();

    if (!apiKey) return;

    // Show loading placeholder (prevents seeing stale options)
    $select.empty().append($('<option>').val('').text(_alfredI18n.loading).prop('disabled', true).prop('selected', true));
    $select.prop('disabled', true);

    $.ajax({
        type: 'POST',
        url: 'plugins/alfred/core/ajax/alfred.ajax.php',
        data: { action: 'listModels', provider: provider, api_key: apiKey },
        dataType: 'json',
        success: function (resp) {
            $select.empty();
            if (resp.state !== 'ok') {
                $select.append($('<option>').val(savedVal).text(savedVal));
                $select.val(savedVal);
                return;
            }
            resp.result.forEach(function (m) {
                $select.append($('<option>').val(m.id).text(m.name));
            });
            // Restore saved selection, or pick first available
            if (savedVal && $select.find('option[value="' + savedVal + '"]').length) {
                $select.val(savedVal);
            } else {
                $select.prop('selectedIndex', 0);
            }
        },
        error: function () {
            $select.empty().append($('<option>').val(savedVal).text(savedVal));
            $select.val(savedVal);
        },
        complete: function () {
            $select.prop('disabled', false);
        }
    });
}

$(document).on('mousedown', '.alfred-model-select', function () {
    alfredLoadModels($(this).closest('.alfred-provider-section'));
});

$('#bt_alfred_test_llm').on('click', function () {
    var $btn      = $(this);
    var $result   = $('#alfred_test_llm_result');
    var provider  = $('#alfred_provider').val();
    var $section  = $('.alfred-provider-section[data-provider="' + provider + '"]');
    var apiKey    = $section.find('.alfred-api-key').val().trim();
    var model     = $section.find('.alfred-model-select').val();

    if (!apiKey) {
        $result.html('<span style="color:#a94442"><i class="fas fa-times"></i> ' + _alfredI18n.enter_api_key + '</span>');
        return;
    }

    $btn.prop('disabled', true);
    $result.html('<i class="fas fa-spinner fa-spin"></i>');

    $.ajax({
        type: 'POST',
        url: 'plugins/alfred/core/ajax/alfred.ajax.php',
        data: { action: 'testLLM', provider: provider, api_key: apiKey, model: model },
        dataType: 'json',
        success: function (data) {
            if (data.state === 'ok') {
                $result.html('<span style="color:#3c763d"><i class="fas fa-check"></i> ' + data.result.model + ' — OK</span>');
            } else {
                $result.html('<span style="color:#a94442"><i class="fas fa-times"></i> ' + (data.result || data.state) + '</span>');
            }
        },
        error: function (jqXHR) {
            $result.html('<span style="color:#a94442"><i class="fas fa-times"></i> ' + jqXHR.responseText + '</span>');
        },
        complete: function () {
            $btn.prop('disabled', false);
        }
    });
});
</script>
