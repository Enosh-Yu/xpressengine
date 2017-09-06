<?php
/**
 * This file is register this package for laravel
 *
 * PHP version 5
 *
 * @category    User
 * @package     Xpressengine\User
 * @author      XE Developers <developers@xpressengine.com>
 * @copyright   2015 Copyright (C) NAVER Corp. <http://www.navercorp.com>
 * @license     http://www.gnu.org/licenses/old-licenses/lgpl-2.1.html LGPL-2.1
 * @link        https://xpressengine.io
 */

namespace App\Providers;

use App\ToggleMenus\User\ManageItem;
use App\ToggleMenus\User\ProfileItem;
use Closure;
use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Contracts\Validation\Factory as Validator;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Xpressengine\Media\MediaManager;
use Xpressengine\Media\Thumbnailer;
use Xpressengine\Storage\Storage;
use Xpressengine\User\EmailBroker;
use Xpressengine\User\Guard;
use Xpressengine\User\GuardInterface;
use Xpressengine\User\Middleware\Admin;
use Xpressengine\User\Models\Guest;
use Xpressengine\User\Models\PendingEmail;
use Xpressengine\User\Models\UnknownUser;
use Xpressengine\User\Models\User;
use Xpressengine\User\Models\UserAccount;
use Xpressengine\User\Models\UserEmail;
use Xpressengine\User\Models\UserGroup;
use Xpressengine\User\Repositories\PendingEmailRepository;
use Xpressengine\User\Repositories\PendingEmailRepositoryInterface;
use Xpressengine\User\Repositories\RegisterTokenRepository;
use Xpressengine\User\Repositories\UserAccountRepository;
use Xpressengine\User\Repositories\UserAccountRepositoryInterface;
use Xpressengine\User\Repositories\UserEmailRepository;
use Xpressengine\User\Repositories\UserEmailRepositoryInterface;
use Xpressengine\User\Repositories\UserGroupRepository;
use Xpressengine\User\Repositories\UserGroupRepositoryInterface;
use Xpressengine\User\Repositories\UserRepository;
use Xpressengine\User\Repositories\UserRepositoryInterface;
use Xpressengine\User\Repositories\VirtualGroupRepository;
use Xpressengine\User\Repositories\VirtualGroupRepositoryInterface;
use Xpressengine\User\UserHandler;
use Xpressengine\User\UserImageHandler;
use Xpressengine\User\UserProvider;

/**
 * laravel 에서 사용하기위해 등록처리를 하는 class
 *
 * @category    User
 * @package     Xpressengine\User
 */
class UserServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->registerHandler();
        $this->registerRepositories();

        // user package 제거후 주석 해제
        $this->registerAuth();
        $this->registerTokenRepository();
        $this->registerEmailBroker();
        $this->registerPasswordBroker();

        $this->registerImageHandler();
    }

    /**
     * register Auth
     *
     * @return void
     */
    protected function registerAuth()
    {
        $this->app->singleton(
            ['xe.auth' => GuardInterface::class],
            function ($app) {
                $adminAuth = $app['config']->get('auth.admin');
                $proxyClass = $app['xe.interception']->proxy(Guard::class, 'Auth');
                return new $proxyClass(
                    new UserProvider($app['hash'], User::class), $app['session.store'], $adminAuth, $app['request']
                );
            }
        );
    }

    /**
     * Register the password broker instance.
     *
     * @return void
     */
    protected function registerPasswordBroker()
    {
        $this->app->singleton(
            'auth.password',
            function ($app) {
                // The password token repository is responsible for storing the email addresses
                // and password reset tokens. It will be used to verify the tokens are valid
                // for the given e-mail addresses. We will resolve an implementation here.
                $tokens = $app['auth.password.tokens'];

                $users = $app['auth']->driver()->getProvider();

                $view = $app['config']['auth.password.email'];

                // The password broker uses a token repository to validate tokens and send user
                // password e-mails, as well as validating that password reset process as an
                // aggregate service of sorts providing a convenient interface for resets.
                $broker = new PasswordBroker($tokens, $users, $app['mailer'], $view);

                // register validator for password
                $broker->validator(
                    function ($credentials) {
                        try {
                            return app('xe.user')->validatePassword($credentials['password']);
                        } catch (\Exception $e) {
                            return false;
                        }
                    }
                );

                return $broker;
            }
        );
    }

    /**
     * Register the email broker instance.
     *
     * @return void
     */
    protected function registerEmailBroker()
    {
        $this->app->singleton(
            'xe.auth.email',
            function ($app) {
                return new EmailBroker($app['xe.user'], $app['mailer']);
            }
        );
    }

    private function registerImageHandler()
    {
        $this->app->singleton(
            'xe.user.image',
            function ($app) {

                $profileImgConfig = config('xe.user.profileImage');

                return new UserImageHandler(
                    $app['xe.storage'], $app['xe.media'], function () {
                    return Thumbnailer::getManager();
                }, $profileImgConfig
                );
            }
        );
    }

    /**
     * Register the token repository implementation.
     *
     * @return void
     */
    protected function registerTokenRepository()
    {
        // register password-token repository
        $this->app->singleton(
            'auth.password.tokens',
            function ($app) {
                $connection = $app['xe.db']->connection('user');

                // The database token repository is an implementation of the token repository
                // interface, and is responsible for the actual storing of auth tokens and
                // their e-mail addresses. We will inject this table and hash key to it.
                $table = $app['config']['auth.password.table'];

                $key = $app['config']['app.key'];

                $expire = $app['config']->get('auth.password.expire', 60);

                return new DatabaseTokenRepository($connection, $table, $key, $expire);
            }
        );

        // register register-token repository
        $this->app->singleton(
            ['xe.user.register.tokens' => RegisterTokenRepository::class],
            function ($app) {
                $connection = $app['xe.db']->connection('user');

                // The database token repository is an implementation of the token repository
                // interface, and is responsible for the actual storing of auth tokens and
                // their e-mail addresses. We will inject this table and hash key to it.
                $table = $app['config']['auth.register.table'];

                $keygen = $app['xe.keygen'];

                $expire = $app['config']->get('auth.register.expire', 60);

                return new RegisterTokenRepository($connection, $keygen, $table, $expire);
            }
        );
    }

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        // set guest's display name
        Guest::setName($this->app['config']['xe.user.guest.name']);

        // set guest's default profile image
        Guest::setDefaultProfileImage($this->app['config']['xe.user.profileImage.default']);

        // set unknown's display name
        UnknownUser::setName($this->app['config']['xe.user.unknown.name']);

        // set unknown's default profile image
        UnknownUser::setDefaultProfileImage($this->app['config']['xe.user.profileImage.default']);

        $this->setProfileImageResolverOfUser();

        // extend xe auth
        $this->extendAuth();

        // set config for validation of password, displayname
        $this->configValidation();

        // register validation extension for email prefix
        $this->extendValidator();

        // register default user skin
        $this->registerDefaultSkins();

        $this->registerSettingsPermissions();

        // register admin middleware
        $this->app['router']->middleware('admin', Admin::class);

        // register toggle menu
        $this->registerToggleMenu();

        // add RegisterGuard and RegiserForm
        $this->addRegisterGuardAndForm();

    }

    /**
     * registerMemberMenu
     *
     * @return void
     */
    protected function registerToggleMenu()
    {
        $this->app['xe.pluginRegister']->add(ProfileItem::class);
        $this->app['xe.pluginRegister']->add(ManageItem::class);
    }

    /**
     * registerHandler
     *
     * @return void
     */
    private function registerHandler()
    {
        $this->app->singleton(
            ['xe.user' => UserHandler::class],
            function ($app) {
                $proxyClass = $app['xe.interception']->proxy(UserHandler::class, 'XeUser');

                $joinConfig = app('xe.config')->get('user.join');

                $userHandler = new $proxyClass(
                    $app['xe.users'],
                    $app['xe.user.accounts'],
                    $app['xe.user.groups'],
                    $app['xe.user.emails'],
                    $app['xe.user.pendingEmails'],
                    $app['xe.user.image'],
                    $app['hash'],
                    $app['validator'],
                    $app['xe.register'],
                    in_array('email', $joinConfig->get('guards', []))
                );
                return $userHandler;
            }
        );
    }

    /**
     * register Repositories
     *
     * @return void
     */
    protected function registerRepositories()
    {
        $this->registerUserRepository();
        $this->registerAccoutRepository();
        $this->registerGroupRepository();
        $this->registerVirtualGroupRepository();
        $this->registerMailRepository();
    }

    protected function registerUserRepository()
    {
        $this->app->singleton(
            ['xe.users' => UserRepositoryInterface::class],
            function ($app) {
                return new UserRepository(User::class);
            }
        );
    }

    /**
     * register Accout Repository
     *
     * @return void
     */
    private function registerAccoutRepository()
    {
        $this->app->singleton(
            ['xe.user.accounts' => UserAccountRepositoryInterface::class],
            function ($app) {
                return new UserAccountRepository(UserAccount::class);
            }
        );
    }

    /**
     * register Group Repository
     *
     * @return void
     */
    protected function registerGroupRepository()
    {
        $this->app->singleton(
            ['xe.user.groups' => UserGroupRepositoryInterface::class],
            function ($app) {
                return new UserGroupRepository(UserGroup::class);
            }
        );
    }

    /**
     * register Virtual Group Repository
     *
     * @return void
     */
    protected function registerVirtualGroupRepository()
    {
        $this->app->singleton(
            ['xe.user.virtualGroups' => VirtualGroupRepositoryInterface::class],
            function ($app) {
                /** @var Closure $vGroups */
                $vGroups = $app['config']->get('xe.group.virtualGroup.all');
                /** @var Closure $getter */
                $getter = $app['config']->get('xe.group.virtualGroup.getByUser');
                return new VirtualGroupRepository($app['xe.users'], $vGroups(), $getter);
            }
        );
    }

    private function registerMailRepository()
    {
        $this->app->singleton(
            ['xe.user.emails' => UserEmailRepositoryInterface::class],
            function ($app) {
                return new UserEmailRepository(UserEmail::class);
            }
        );

        $this->app->singleton(
            ['xe.user.pendingEmails' => PendingEmailRepositoryInterface::class],
            function ($app) {
                return new PendingEmailRepository(PendingEmail::class);
            }
        );
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [
            'xe.auth',
            'xe.auth.password',
            'xe.auth.email',
            'xe.auth.tokens',
            'xe.user.register.tokens',
            'xe.user',
            'xe.users',
            'xe.user.groups',
            'xe.user.virtualGroups',
            'xe.user.emails',
            'xe.user.pendingEmails',
            'xe.user.accounts'
        ];
    }

    /**
     * extendAuth
     *
     * @return void
     */
    private function extendAuth()
    {
        $this->app['auth']->extend(
            'xe',
            function ($app) {
                return $app['xe.auth'];
            }
        );
    }

    private function configValidation()
    {
        // set password validation to config
        $passwordLevels =  [
            'weak' => [
                'title' => 'xe::weak',
                'validate' => function ($password) {
                    return strlen($password) >= 4;
                },
                'description' => 'xe::passwordStrengthWeakDescription'
            ],
            'normal' => [
                'title' => 'xe::normal',
                'validate' => function ($password) {
                    if (!preg_match_all(
                        '$\S*(?=\S{6,})(?=\S*[a-zA-Z])(?=\S*[\d])\S*$',
                        $password
                    )
                    ) {
                        return false;
                    }
                    return true;
                },
                'description' => 'xe::passwordStrengthNormalDescription'
            ],
            'strong' => [
                'title' => 'xe::strong',
                'validate' => function ($password) {
                    if (!preg_match_all(
                        '$\S*(?=\S{8,})(?=\S*[a-zA-Z])(?=\S*[\d])(?=\S*[\W])\S*$',
                        $password
                    )
                    ) {
                        return false;
                    }
                    return true;
                },
                'description' => 'xe::passwordStrengthStrongDescription'
            ]
        ];
        app('config')->set('xe.user.password.levels', $passwordLevels);

        // set display name validation to config
        app('config')->set('xe.user.displayName.validate', function ($value) {
            if (!is_string($value) && !is_numeric($value)) {
                return false;
            }

            if (str_contains($value, "  ")) {
                return false;
            }

            $byte = strlen($value);
            $multiByte = mb_strlen($value);

            if ($byte === $multiByte) {
                if ($byte < 3) {
                    return false;
                }
            } else {
                if ($multiByte < 2) {
                    return false;
                }
            }
            return preg_match('/^[\pL\pM\pN][. \pL\pM\pN_-]*[\pL\pM\pN]$/u', $value);
        });

    }

    /**
     * extendEmailPrefixValidator
     *
     * @return void
     */
    private function extendValidator()
    {
        /** @var Validator $validator */
        $validator = $this->app['validator'];

        // 도메인이 생략된 이메일 validation 추가
        $validator->extend(
            'email_prefix',
            function ($attribute, $value, $parameters) {
                if (!str_contains($value, '@')) {
                    $value .= '@test.com';
                }
                return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
            }
        );

        // 표시이름 validation 추가
        /** @var Closure $displayNameValidate */
        $displayNameValidate = app('config')->get('xe.user.displayName.validate');
        $validator->extend(
            'display_name',
            function ($attribute, $value, $parameters) use ($displayNameValidate) {
                return $displayNameValidate($value);
            }
        );

        $passwordConfig = app('config')->get('xe.user.password');
        $levels = $passwordConfig['levels'];
        $level = $levels[$passwordConfig['default']];
        $validate = $level['validate'];

        $validator->extend(
            'password',
            function ($attribute, $value, $parameters) use ($validate) {
                return $validate($value);
            },
            xe_trans($level['description'])
        );
    }

    /**
     * registerDefaultSkins
     *
     * @return void
     */
    private function registerDefaultSkins()
    {
        $pluginRegister = $this->app['xe.pluginRegister'];
        $pluginRegister->add(\App\Skins\Member\AuthSkin::class);
        $pluginRegister->add(\App\Skins\Member\SettingsSkin::class);
        $pluginRegister->add(\App\Skins\Member\ProfileSkin::class);
    }

    private function registerSettingsPermissions()
    {
        $permissions = [
            'user.list' => [
                'title' => '회원정보 보기',
                'tab' => '회원'
            ],
            'user.edit' => [
                'title' => '회원정보 수정',
                'tab' => '회원'
            ]
        ];
        $register = $this->app->make('xe.register');
        foreach ($permissions as $id => $permission) {
            $register->push('settings/permission', $id, $permission);
        }
    }

    private function setProfileImageResolverOfUser()
    {
        $default = $this->app['config']['xe.user.profileImage.default'];
        $storage = $this->app['xe.storage'];
        $media = $this->app['xe.media'];
        User::setProfileImageResolver(
            function ($imageId) use ($default, $storage, $media) {
                try {
                    if($imageId !== null) {
                        /** @var Storage $storage */
                        $file = $storage->find($imageId);

                        if ($file !== null) {
                            /** @var MediaManager $media */
                            $mediaFile = $media->make($file);
                            return asset($mediaFile->url());
                        }
                    }
                } catch(\Exception $e) {
                }

                return asset($default);
            }
        );
    }

    protected function addRegisterGuardAndForm()
    {

        // email register guard
        $this->app['xe.register']->push(
            'user/register/guard',
            'email',
            [
                'title' => '이메일 인증',
                'description' => '사용자의 이메일로 인증정보를 전송하여 인증합니다. "인증없이 가입 허용"을 사용할 경우 비활성화됩니다.',
                'render' => function () {
                    $config = app('xe.config')->get('user.join');
                    if($config->get('guard_forced') === false) {
                        return;
                    }
                    $skinHandler = app('xe.skin');
                    $skin = $skinHandler->getAssigned('user/auth');
                    return $skin->setView('register.sections.email')->render();
                }
            ]
        );

        // email register form
        $this->app['xe.register']->push(
            'user/register/form',
            'email',
            [
                'title' => '이메일 인증번호',
                'description' => '인증번호를 입력하는 폼입니다. 이메일 인증시에만 출력되며, 상단에 배치하시기 바랍니다.',
                'forced' => true,
                'render' => function ($token) {
                    if ($token->guard !== 'email') {
                        return null;
                    }
                    $code = request()->get('code');

                    if ($code !== null) {
                        $email = app('xe.user')->pendingEmails()->findByAddress($token->email);

                        if ($email->getConfirmationCode() !== $code) {
                            throw new HttpException(400, '잘못된 이메일 인증 코드입니다.');
                        }
                    }

                    app('xe.frontend')->html('email.setter')->content("
                        <script>
                            $('input[name=email]').attr('readonly','readonly').val('{$token->email}');
                        </script>
                    ")->load();

                    $skinHandler = app('xe.skin');
                    $skin = $skinHandler->getAssigned('user/auth');
                    return $skin->setView('register.forms.confirm')->setData(compact('token', 'code'))->render();
                }
            ]
        );

        // 기본정보 form 추가
        $this->app['xe.register']->push(
            'user/register/form',
            'default-info',
            [
                'title' => '기본정보',
                'description' => '기본정보(이메일, 이름, 비밀번호) 입력 폼입니다.',
                'forced' => true,
                'render' => function ($data) {
                    $skinHandler = app('xe.skin');
                    $skin = $skinHandler->getAssigned('user/auth');
                    // password configuration
                    $passwordConfig = app('config')->get('xe.user.password');
                    $passwordLevel = array_get($passwordConfig['levels'], $passwordConfig['default']);
                    return $skin->setView('register.forms.default')->setData(compact('data', 'passwordLevel'))->render(
                    );
                }
            ]
        );

        // dynamic field form 추가
        $this->app['xe.register']->push(
            'user/register/form',
            'dynamic-fields',
            [
                'title' => '부가 정보',
                'description' => '추가된 확장필드들의 입력 폼입니다.',
                'forced' => true,
                'render' => function ($data) {
                    $dynamicField = app('xe.dynamicField');
                    $fieldTypes = $dynamicField->gets('user');

                    $html = '';
                    foreach ($fieldTypes as $id => $fieldType) {
                        $html .= sprintf(
                            '<div class="control-group">%s</div>',
                            $fieldType->getSkin()->create(request()->all())
                        );
                    }

                    $skinHandler = app('xe.skin');
                    $skin = $skinHandler->getAssigned('user/auth');
                    return $skin->setView('register.forms.dfields')->setData(compact('data', 'html'))->render();
                }
            ]
        );

        // agreement form 추가
        $this->app['xe.register']->push(
            'user/register/form',
            'agreements',
            [
                'title' => '이용약관 동의',
                'description' => '이용약관 동의 체크박스입니다.',
                'forced' => true,
                'render' => function ($data) {

                    app('xe.frontend')->html('auth.register')->content(
                        "
                    <script>
                        $(function($) {
                            $('.__xe_btn_privacy').click(function(){
                                XE.pageModal('".route('auth.privacy')."');
                                return false;
                            });
                            $('.__xe_btn_agreement').click(function(){
                                XE.pageModal('".route('auth.agreement')."');
                                return false;
                            })
                        });
                    </script>
                "
                    )->load();

                    $skinHandler = app('xe.skin');
                    $skin = $skinHandler->getAssigned('user/auth');
                    return $skin->setView('register.forms.agreements')->setData(compact('data'))->render();
                }
            ]
        );

        // captcha form 추가
        $this->app['xe.register']->push(
            'user/register/form',
            'captcha',
            [
                'title' => '캡차',
                'description' => '캡차 문자 입력 폼입니다.',
                'render' => function ($data) {
                    return uio('captcha');
                }
            ]
        );

        // email validation
        intercept(
            'XeUser@validateForCreate',
            'register.email.validator',
            function ($target, $data, $token = null) {

                if (data_get($token, 'guard') === 'email') {
                    $code = array_get($data, 'code');
                    $email = app('xe.user')->pendingEmails()->findByAddress($token->email);

                    if ($email->getConfirmationCode() !== $code) {
                        throw new HttpException(400, '잘못된 이메일 인증 코드입니다.');
                    }

                    $data['id'] = $email->userId;
                }

                return $target($data, $token);
            }
        );
    }
}
