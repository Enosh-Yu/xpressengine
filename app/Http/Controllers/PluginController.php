<?php
/**
 * @author      XE Developers <developers@xpressengine.com>
 * @copyright   2015 Copyright (C) NAVER Corp. <http://www.navercorp.com>
 * @license     http://www.gnu.org/licenses/old-licenses/lgpl-2.1.html LGPL-2.1
 * @link        https://xpressengine.io
 */

namespace App\Http\Controllers;

use Carbon\Carbon;
use Composer\Console\Application;
use Composer\Json\JsonFormatter;
use Composer\Util\Platform;
use Illuminate\Session\SessionManager;
use Log;
use Redirect;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use XePresenter;
use Xpressengine\Http\Request;
use Xpressengine\Installer\XpressengineInstaller;
use Xpressengine\Interception\InterceptionHandler;
use Xpressengine\Plugin\Composer\Composer;
use Xpressengine\Plugin\Composer\ComposerFileWriter;
use Xpressengine\Plugin\PluginHandler;
use Xpressengine\Plugin\PluginProvider;
use Xpressengine\Support\Exceptions\XpressengineException;

class PluginController extends Controller
{

    /**
     * PluginController constructor.
     */
    public function __construct()
    {
        XePresenter::setSettingsSkinTargetId('plugins');
    }

    protected function index(
        Request $request,
        PluginHandler $handler,
        PluginProvider $provider,
        ComposerFileWriter $writer
    ) {

        $installType = $request->get('install_type', 'fetched');

        // filter input
        $field = [];
        $field['component'] = $request->get('component');
        $field['status'] = $request->get('status');
        $field['keyword'] = $request->get('query');

        if ($field['keyword'] === '') {
            $field['keyword'] = null;
        }

        $collection = $handler->getAllPlugins(true);
        $filtered = $collection->fetch($field);
        $plugins = $collection->fetchByInstallType($installType, $filtered);

        // $provider->sync($plugins);

        $componentTypes = $this->getComponentTypes();

        $unresolvedComponents = $handler->getUnresolvedComponents();

        if ($installType === 'fetched') {
            $operation = $handler->getOperation($writer);
            return XePresenter::make('index.fetched', compact('plugins', 'componentTypes', 'operation', 'installType', 'unresolvedComponents'));
        } else {
            return XePresenter::make('index.self-installed', compact('plugins', 'componentTypes', 'installType', 'unresolvedComponents'));
        }
    }

    public function getOperation(PluginHandler $handler, ComposerFileWriter $writer)
    {
        $operation = $handler->getOperation($writer);
        return api_render('operation', compact('operation'), compact('operation'));
    }

    public function deleteOperation(ComposerFileWriter $writer)
    {
        $writer->reset()->cleanOperation()->write();
        return XePresenter::makeApi(['type' => 'success', 'message' => '삭제되었습니다.']);
    }

    public function getDelete(Request $request, PluginHandler $handler)
    {
        $pluginIds = $request->get('pluginId');
        $pluginIds = explode(',', $pluginIds);

        $collection = $handler->getAllPlugins(true);
        $plugins = $collection->getList($pluginIds);

        return api_render('index.delete', compact('plugins'));
    }

    public function delete(
        Request $request,
        PluginHandler $handler,
        ComposerFileWriter $writer
    ) {

        $operation = $handler->getOperation($writer);
        if ($operation['status'] === ComposerFileWriter::STATUS_RUNNING) {
            throw new HttpException(422, "이미 진행중인 요청이 있습니다.");
        }

        $handler->getAllPlugins(true);

        $pluginIds = $request->get('pluginId', []);

        if (empty($pluginIds)) {
            throw new HttpException(422, "선택된 플러그인이 없습니다.");
        }

        $collection = $handler->getAllPlugins(true);

        $plugins = $collection->getList($pluginIds);

        foreach ($plugins as $plugin) {
            if ($plugin === null) {
                throw new HttpException(422, 'Plugin not found.');
            }
            if ($plugin->isActivated()) {
                $handler->deactivatePlugin($plugin->getId());
            }
        }

        $timeLimit = config('xe.plugin.operation.time_limit');
        $writer->reset()->cleanOperation();
        $expiredTime = Carbon::now()->addSeconds($timeLimit)->toDateTimeString();

        $packages = [];
        foreach ($plugins as $plugin) {
            if ($plugin->isSelfInstalled()) {
                $handler->uninstallPlugin($plugin->getId());
            } else {
                $writer->uninstall($plugin->getName(), $expiredTime)->write();
                $packages[] = $plugin->getName();
            }
        }

        $this->reserveOperation($handler, $writer, $timeLimit, $packages);

        return redirect()->route('settings.plugins')->with(
            'alert',
            ['type' => 'success', 'message' => '플러그인을 삭제중입니다.']
        );
    }


    public function getActivate(Request $request, PluginHandler $handler)
    {
        $pluginIds = $request->get('pluginId');
        $pluginIds = explode(',', $pluginIds);

        $collection = $handler->getAllPlugins(true);
        $plugins = $collection->getList($pluginIds);

        return api_render('index.activate', compact('plugins'));
    }

    public function activate(Request $request, PluginHandler $handler, InterceptionHandler $interceptionHandler)
    {
        $handler->getAllPlugins(true);

        $pluginIds = $request->get('pluginId');
        if (empty($pluginIds)) {
            throw new HttpException(422, "선택된 플러그인이 없습니다.");
        }

        $collection = $handler->getAllPlugins(true);
        $plugins = $collection->getList($pluginIds);

        try {
            foreach ($plugins as $plugin) {
                if ($plugin === null) {
                    throw new HttpException(422, 'Plugin not found.');
                }
                if (!$plugin->isActivated()) {
                    $handler->activatePlugin($plugin->getId());
                }
            }
            $interceptionHandler->clearProxies();
        } catch (XpressengineException $e) {
            throw new HttpException(Response::HTTP_FORBIDDEN, $e->getMessage(), $e);
        } catch (\Exception $e) {
            throw $e;
        }

        return Redirect::back()->withAlert(['type' => 'success', 'message' => '플러그인을 켰습니다.']);
    }

    public function getDeactivate(Request $request, PluginHandler $handler)
    {
        $pluginIds = $request->get('pluginId');
        $pluginIds = explode(',', $pluginIds);

        $collection = $handler->getAllPlugins(true);
        $plugins = $collection->getList($pluginIds);

        return api_render('index.deactivate', compact('plugins'));
    }

    public function deactivate(Request $request, PluginHandler $handler, InterceptionHandler $interceptionHandler)
    {
        $handler->getAllPlugins(true);

        $pluginIds = $request->get('pluginId');
        if (empty($pluginIds)) {
            throw new HttpException(422, "선택된 플러그인이 없습니다.");
        }

        $collection = $handler->getAllPlugins(true);
        $plugins = $collection->getList($pluginIds);

        try {
            foreach ($plugins as $plugin) {
                if ($plugin === null) {
                    throw new HttpException(422, 'Plugin not found.');
                }
                if ($plugin->isActivated()) {
                    $handler->deactivatePlugin($plugin->getId());
                }
            }
            $interceptionHandler->clearProxies();
        } catch (XpressengineException $e) {
            throw new HttpException(Response::HTTP_FORBIDDEN, $e->getMessage(), $e);
        } catch (\Exception $e) {
            throw $e;
        }

        return Redirect::back()->withAlert(['type' => 'success', 'message' => '플러그인을 껐습니다.']);
    }

    public function getDownload(
        Request $request,
        PluginHandler $handler,
        PluginProvider $provider,
        ComposerFileWriter $writer
    )
    {
        $collection = $handler->getAllPlugins(true);
        $fetched = $collection->fetchByInstallType('fetched');

        $provider->sync($fetched);

        $plugins = array_where(
            $fetched,
            function ($key, $plugin) {
                return $plugin->hasUpdate();
            }
        );

        $available = ini_get('allow_url_fopen') ? true : false;


        return api_render('index.update', compact('plugins', 'available'));
    }

    public function download(
        Request $request,
        PluginHandler $handler,
        PluginProvider $provider,
        ComposerFileWriter $writer
    ) {
        $operation = $handler->getOperation($writer);
        if ($operation['status'] === ComposerFileWriter::STATUS_RUNNING) {
            throw new HttpException(422, "이미 진행중인 요청이 있습니다.");
        }

        $plugins = $request->get('plugin');

        $collection = $handler->getAllPlugins(true);
        $fetched = $collection->fetchByInstallType('fetched');

        $provider->sync($fetched);

        $timeLimit = config('xe.plugin.operation.time_limit');
        $writer->reset()->cleanOperation();

        $packages = [];
        foreach ($plugins as $id => $info) {
            if(array_get($info, 'update', false)) {
                $plugin = $fetched[$id];
                $writer->update(
                    $plugin->getName(),
                    array_get($info, 'version'),
                    Carbon::now()->addSeconds($timeLimit)->toDateTimeString()
                )->write();
                $packages[] = $plugin->getName();
            }
        }

        if (empty($packages)) {
            throw new HttpException(422, "선택된 플러그인이 없습니다.");
        }

        $this->reserveOperation(
            $handler,
            $writer,
            $timeLimit,
            $packages
        );

        return redirect()->route('settings.plugins')->with(
            'alert',
            ['type' => 'success', 'message' => '플러그인의 새로운 버전을 다운로드하는 중입니다.']
        );
    }

    public function install(
        Request $request,
        PluginHandler $handler,
        PluginProvider $provider,
        ComposerFileWriter $writer,
        SessionManager $session
    )
    {

        $operation = $handler->getOperation($writer);

        if ($operation['status'] === ComposerFileWriter::STATUS_RUNNING) {
            throw new HttpException(422, "이미 진행중인 요청이 있습니다.");
        }

        $pluginIds = $request->get('pluginId');

        $handler->getAllPlugins(true);

        // 자료실에서 플러그인 정보 조회
        $pluginsData = $provider->findAll($pluginIds);

        if ($pluginsData === null) {
            throw new HttpException(
                422, "Can not find the plugin that should be installed from the Market-place."
            );
        }

        $timeLimit = config('xe.plugin.operation.time_limit');
        $writer->reset()->cleanOperation();

        $packages = [];
        foreach ($pluginsData as $pluginData) {
            $name = $pluginData->name;
            $version = $pluginData->latest_release->version;
            $writer->install($name, $version, Carbon::now()->addSeconds($timeLimit)->toDateTimeString());
            $packages[] = $name;
        }
        $writer->write();
        $this->reserveOperation($handler, $writer, $timeLimit, $packages);

        return redirect()->back()->with('alert', ['type' => 'success', 'message' => '새로운 플러그인을 설치중입니다.']);
    }

    /**
     * reserveInstall
     *
     * @param ComposerFileWriter $writer
     * @param int                $timeLimit
     * @param array              $packages
     * @param null               $callback
     */
    protected function reserveOperation(PluginHandler $handler, ComposerFileWriter $writer, $timeLimit, $packages, $callback = null)
    {
        $this->prepareComposer($timeLimit);

        /** @var \Illuminate\Foundation\Application $app */
        app()->terminating(
            function () use ($handler, $writer, $packages, $callback) {
                $pid = getmypid();
                Log::info("[plugin operation] start running composer run [pid=$pid]");

                $params = [
                    'command' => 'update',
                    "--with-dependencies" => true,
                    '--working-dir' => base_path(),
                    '--verbose' => 1,
                    'packages' => $packages
                ];
                $input = new ArrayInput(
                    $params
                );

                $config = app('xe.config')->get('plugin');
                $siteToken = $config->get('site_token');
                if ($siteToken) {
                    Composer::setPackagistToken($siteToken);
                }
                Composer::setPackagistUrl(config('xe.plugin.packagist.url'));

                $startTime = Carbon::now()->format('YmdHis');
                $logFileName = "logs/plugin-$startTime.log";

                file_put_contents(
                    storage_path($logFileName),
                    JsonFormatter::format(json_encode($params), true, true).PHP_EOL
                );

                $writer->set('xpressengine-plugin.operation.log', $logFileName);
                $writer->write();

                $output = new StreamOutput(fopen(storage_path($logFileName), 'a', false));
                $application = new Application();
                $application->setAutoExit(false); // prevent `$application->run` method from exitting the script
                if (!defined('__XE_PLUGIN_MODE__')) {
                    define('__XE_PLUGIN_MODE__', true);
                }
                $result = $application->run($input, $output);

                $handler->refreshPlugins();

                if (is_callable($callback)) {
                    $callback($result);
                }

                $writer->load();

                if ($result !== 0) {
                    $writer->set('xpressengine-plugin.operation.status', ComposerFileWriter::STATUS_FAILED);
                    $writer->set('xpressengine-plugin.operation.failed', XpressengineInstaller::$failed);
                } else {
                    $writer->set('xpressengine-plugin.operation.status', ComposerFileWriter::STATUS_SUCCESSED);
                }
                $writer->write();

                Log::info(
                    "[plugin operation] plugin operation finished. [exit code: $result, memory usage: ".memory_get_usage(
                    )."]"
                );
            }
        );
    }

    protected function prepareComposer($timeLimit)
    {

        $files = [
            storage_path('app/composer.plugins.json'),
            base_path('composer.lock'),
            base_path('plugins/'),
            base_path('vendor/'),
            base_path('vendor/composer/installed.json'),
        ];

        // file permission check

        foreach ($files as $file) {
            $type = is_dir($file) ? '디렉토리' : '파일';

            if (!is_writable($file)) {
                throw new HttpException(500, "[$file] {$type}의 쓰기 권한이 없습니다. 플러그인을 설치하기 위해서는 이 {$type}의 쓰기 권한이 있어야 합니다");
            }
        }

        // composer home check
        $this->checkComposerHome();

        set_time_limit($timeLimit);
        ignore_user_abort(true);
        ini_set('allow_url_fopen', '1');

        $memoryInBytes = function ($value) {
            $unit = strtolower(substr($value, -1, 1));
            $value = (int) $value;
            switch ($unit) {
                case 'g':
                    $value *= 1024;
                // no break (cumulative multiplier)
                case 'm':
                    $value *= 1024;
                // no break (cumulative multiplier)
                case 'k':
                    $value *= 1024;
            }
            return $value;
        };

        $memoryLimit = trim(ini_get('memory_limit'));
        // Increase memory_limit if it is lower than 1GB
        if ($memoryLimit != -1 && $memoryInBytes($memoryLimit) < 1024 * 1024 * 1024) {
            ini_set('memory_limit', '1G');
        }

    }

    /**
     * checkComposerHome
     *
     * @return void
     * @throws \Exception
     */
    protected function checkComposerHome()
    {
        $config = app('xe.config')->get('plugin');
        $home = $config->get('composer_home');

        if ($home) {
            putenv("COMPOSER_HOME=$home");
        } else {
            $home = getenv('COMPOSER_HOME');
        }

        if (Platform::isWindows()) {
            if (!getenv('APPDATA')) {
                throw new HttpException(500,
                    'COMPOSER_HOME 환경변수가 설정되어 있지 않습니다. <a href="'.route('settings.plugins.setting.show').'">플러그인 설정</a>에서 설정할 수 있습니다.'
                );
            }
        }

        if (!$home) {
            $home = getenv('HOME');
            if (!$home) {
                throw new HttpException(500,
                    'COMPOSER_HOME 환경변수가 설정되어 있지 않습니다. <a href="'.route('settings.plugins.setting.show').'">플러그인 설정</a>에서 설정할 수 있습니다.'
                );
            }
        }
    }

    public function show($pluginId, PluginHandler $handler, PluginProvider $provider)
    {
        // refresh plugin cache
        $handler->getAllPlugins(true);

        $componentTypes = $this->getComponentTypes();

        $plugin = $handler->getPlugin($pluginId);

        $provider->sync($plugin);

        $unresolvedComponents = $handler->getUnresolvedComponents($pluginId);

        return XePresenter::make('show', compact('plugin', 'componentTypes', 'unresolvedComponents'));
    }

    public function putActivatePlugin($pluginId, PluginHandler $handler, InterceptionHandler $interceptionHandler)
    {
        try {
            $handler->activatePlugin($pluginId);
            $interceptionHandler->clearProxies();
        } catch (XpressengineException $e) {
            throw new HttpException(Response::HTTP_FORBIDDEN, $e->getMessage(), $e);
        } catch (\Exception $e) {
            throw $e;
        }

        return Redirect::back()->withAlert(['type' => 'success', 'message' => '플러그인을 켰습니다.']);
    }

    public function putDeactivatePlugin($pluginId, PluginHandler $handler, InterceptionHandler $interceptionHandler)
    {
        try {
            $handler->deactivatePlugin($pluginId);
            $interceptionHandler->clearProxies();
        } catch (XpressengineException $e) {
            throw new HttpException(Response::HTTP_FORBIDDEN, $e->getMessage(), $e);
        } catch (\Exception $e) {
            throw $e;
        }

        return Redirect::back()->withAlert(['type' => 'success', 'message' => '플러그인을 껐습니다.']);
    }

    public function putUpdatePlugin($pluginId, PluginHandler $handler, InterceptionHandler $interceptionHandler)
    {
        try {
            $handler->updatePlugin($pluginId);
            $interceptionHandler->clearProxies();
        } catch (XpressengineException $e) {
            throw new HttpException(Response::HTTP_FORBIDDEN, $e->getMessage(), $e);
        } catch (\Exception $e) {
            throw $e;
        }

        return Redirect::back()->withAlert(['type' => 'success', 'message' => '플러그인의 수정사항을 적용했습니다.']);
    }


    /**
     * getComponentTypes
     *
     * @return array
     */
    protected function getComponentTypes()
    {
        $componentTypes = [
            'theme' => '테마',
            'skin' => '스킨',
            'settingsSkin' => '설정스킨',
            'settingsTheme' => '관리페이지테마',
            'widget' => '위젯',
            'module' => '모듈',
            'editor' => '에디터',
            'editortool' => '에디터툴',
            'uiobject' => 'UI오브젝트',
            'FieldType' => '다이나믹필드',
            'FieldSkin' => '다이나믹필드스킨',
        ];
        return $componentTypes;
    }
}

