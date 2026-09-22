<?php
// ============ فرانت‌کنترلر و روتر API (PHP/MySQL) ============

$ROOT = __DIR__ . '/..';

require "$ROOT/lib/Db.php";
require "$ROOT/lib/Jwt.php";
require "$ROOT/lib/Http.php";
require "$ROOT/lib/Media.php";
require "$ROOT/lib/Xlsx.php";
require "$ROOT/lib/Sms.php";

$CONFIG = require "$ROOT/config.php";


// ============================================================
// CORS
// ============================================================

$allowed = array_filter([
    $CONFIG['public_url'] ?: '*'
]);

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin) {
    header(
        'Access-Control-Allow-Origin: ' . $origin
    );
}

header('Vary: Origin');

header(
    'Access-Control-Allow-Headers: ' .
    'Content-Type, Authorization, X-Requested-With'
);

header(
    'Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS'
);

if (
    $_SERVER['REQUEST_METHOD'] === 'OPTIONS'
) {
    http_response_code(204);
    exit;
}


// ============================================================
// Request
// ============================================================

$method =
    $_SERVER['REQUEST_METHOD'];

$path =
    parse_url(
        $_SERVER['REQUEST_URI'],
        PHP_URL_PATH
    );

$path =
    rtrim(
        $path,
        '/'
    );

if ($path === '') {
    $path = '/';
}


// ============================================================
// سلامت عمومی
// ============================================================

if (
    $path === '/health' ||
    $path === '/api/health'
) {

    $db_ok = false;

    try {

        Db::pdo()->query('SELECT 1');

        $db_ok = true;

    } catch (Throwable $e) {

        $db_ok = false;
    }

    Http::json([
        'ok'        => true,
        'installed' => is_file(
            "$ROOT/.installed"
        ),
        'db'        => $db_ok
    ]);
}


// ============================================================
// سرو پنل وب برای مسیرهای غیر API
// ============================================================

if (
    strpos(
        $path,
        '/api'
    ) !== 0
) {

    if (
        $path === '/' ||
        $path === '/index.php' ||
        $path === '/panel.html'
    ) {

        $panelFile =
            __DIR__ . '/panel.html';


        // ----------------------------------------------------
        // بررسی وجود panel.html
        // ----------------------------------------------------

        if (
            !is_file($panelFile)
        ) {

            http_response_code(500);

            echo 'panel.html not found';

            exit;
        }


        // ----------------------------------------------------
        // خواندن panel.html
        // ----------------------------------------------------

        $html =
            file_get_contents(
                $panelFile
            );

        if (
            $html === false
        ) {

            http_response_code(500);

            echo 'Unable to read panel.html';

            exit;
        }


        // ----------------------------------------------------
        // اسکریپت محاسبه هزینه ۹ ماهه
        // ----------------------------------------------------

        $featureScript =
            '<script src="assets/home-features.js?v=1"></script>';


        // ----------------------------------------------------
        // جلوگیری از اضافه شدن چندباره اسکریپت
        // ----------------------------------------------------

        if (
            stripos(
                $html,
                'assets/home-features.js'
            ) === false
        ) {

            $updatedHtml =
                preg_replace(
                    '/<\/body\s*>/i',
                    $featureScript .
                    "\n</body>",
                    $html,
                    1
                );

            if (
                $updatedHtml !== null &&
                $updatedHtml !== $html
            ) {

                $html =
                    $updatedHtml;

            } else {

                /*
                 * اگر panel.html تگ body نداشت،
                 * اسکریپت در انتهای فایل اضافه می‌شود.
                 */

                $html .=
                    "\n" .
                    $featureScript .
                    "\n";
            }
        }


        // ----------------------------------------------------
        // Header
        // ----------------------------------------------------

        header(
            'Content-Type: text/html; charset=UTF-8'
        );

        header(
            'Cache-Control: no-cache, no-store, must-revalidate'
        );

        header(
            'Pragma: no-cache'
        );

        header(
            'Expires: 0'
        );


        echo $html;

        exit;
    }


    http_response_code(404);

    echo 'Not Found';

    exit;
}


// ============================================================
// Router
// ============================================================

$routes = [];


// scope:
//   admin
//   company
//   any
//
// تعیین می‌کند توکن چه نوع کاربری مجاز است.
//

function route(
    $m,
    $p,
    $fn,
    $public = false,
    $scope = 'any'
) {

    global $routes;

    $routes[] = compact(
        'm',
        'p',
        'fn',
        'public',
        'scope'
    );
}


require "$ROOT/lib/routes.php";


// ============================================================
// Request body
// ============================================================

$body =
    Http::body();


// ============================================================
// انتخاب دقیق‌ترین Route
// ============================================================

$candidates = [];

foreach (
    $routes as $r
) {

    if (
        $r['m'] !== $method
    ) {
        continue;
    }


    $pattern =
        '#^' .
        preg_replace(
            '#\{(\w+)\}#',
            '(?P<$1>[^/]+)',
            $r['p']
        ) .
        '$#u';


    if (
        !preg_match(
            $pattern,
            $path,
            $mm
        )
    ) {
        continue;
    }


    $params =
        array_filter(
            $mm,
            fn($k) =>
                !is_int($k),
            ARRAY_FILTER_USE_KEY
        );


    /*
     * تعداد پارامترهای متغیر.
     *
     * هرچه کمتر باشد،
     * Route دقیق‌تر است.
     */

    $specificity =
        substr_count(
            $r['p'],
            '{'
        );


    $candidates[] = [
        'route'       => $r,
        'params'      => $params,
        'specificity' => $specificity
    ];
}


// ============================================================
// مرتب‌سازی Routeها
// ============================================================

usort(
    $candidates,
    fn($a, $b) =>
        $a['specificity']
        <=>
        $b['specificity']
);


// ============================================================
// اجرای Route
// ============================================================

foreach (
    $candidates as $c
) {

    $r =
        $c['route'];

    $params =
        $c['params'];

    $user = null;


    // --------------------------------------------------------
    // احراز هویت
    // --------------------------------------------------------

    if (
        !$r['public']
    ) {

        $token =
            Http::bearer();

        $payload =
            $token
                ? Jwt::verify(
                    $token,
                    $CONFIG['jwt_secret']
                )
                : null;


        if (!$payload) {

            Http::error(
                'احراز هویت نامعتبر است. دوباره وارد شوید.',
                401
            );
        }


        // ----------------------------------------------------
        // بررسی Scope
        // ----------------------------------------------------

        if (
            $r['scope'] !== 'any' &&
            (
                $payload['scope'] ?? null
            ) !== $r['scope']
        ) {

            Http::error(
                'دسترسی مجاز نیست.',
                403
            );
        }


        $user =
            $payload;
    }


    // --------------------------------------------------------
    // اجرای Handler
    // --------------------------------------------------------

    try {

        $result =
            $r['fn'](
                $params,
                $body,
                $user
            );


        Http::json(
            $result === null
                ? ['ok' => true]
                : $result
        );

    } catch (
        Throwable $e
    ) {

        error_log(
            'API error [' .
            $path .
            ']: ' .
            $e->getMessage()
        );


        Http::error(
            'خطای داخلی سرور: ' .
            $e->getMessage(),
            500
        );
    }


    exit;
}


// ============================================================
// Route پیدا نشد
// ============================================================

Http::error(
    'مسیر یافت نشد',
    404
);