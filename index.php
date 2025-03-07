<?php
session_start();

// Archivo de base de datos
$dbFile = '../databases/khaki.sqlite';

// Fechas límite
define('START_DATE', '2025-03-03');  // Antes de esta fecha NO se puede editar
define('END_DATE',   '2025-06-13');  // Después de esta fecha NO se puede editar

/**
 * Conexión SQLite + creación de tablas si no existen
 */
function getDBConnection()
{
    global $dbFile;
    $firstTime = !file_exists($dbFile);

    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($firstTime) {
        // Tabla de usuarios
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                username TEXT NOT NULL UNIQUE,
                password TEXT NOT NULL
            )
        ");
        // Usuario inicial (admin)
        $hashPassword = password_hash('jocarsa', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            INSERT INTO users (name, email, username, password)
            VALUES (:name, :email, :username, :password)
        ");
        $stmt->execute([
            ':name'     => 'Jose Vicente Carratala',
            ':email'    => 'info@josevicentecarratala.com',
            ':username' => 'jocarsa',
            ':password' => $hashPassword
        ]);

        // Tabla de calendarios
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS calendars (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                date TEXT NOT NULL,
                hours REAL NOT NULL,
                UNIQUE(user_id, date)
            )
        ");
    }

    return $pdo;
}

/**
 * Obtener usuario por username
 */
function getUserByUsername($username)
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :u LIMIT 1");
    $stmt->execute([':u' => $username]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Registrar usuario (password_hash)
 */
function registerUser($name, $email, $username, $password)
{
    // Si existe el username, fallo
    if (getUserByUsername($username)) {
        return false;
    }
    $pdo = getDBConnection();
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("
        INSERT INTO users (name, email, username, password)
        VALUES (:name, :email, :user, :pass)
    ");
    return $stmt->execute([
        ':name' => $name,
        ':email' => $email,
        ':user' => $username,
        ':pass' => $hashed
    ]);
}

/**
 * Iniciar sesión (password_verify)
 */
function loginUser($username, $password)
{
    $user = getUserByUsername($username);
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['username']  = $user['username'];
        $_SESSION['name']      = $user['name'];
        return true;
    }
    return false;
}

/**
 * Verifica si hay sesión iniciada
 */
function isLoggedIn()
{
    return isset($_SESSION['user_id']);
}

/**
 * Cierra la sesión
 */
function logoutUser()
{
    session_unset();
    session_destroy();
}

/**
 * Genera array de fechas 'YYYY-MM-DD' para los meses indicados
 */
function getDateRange($months)
{
    $dates = [];
    foreach ($months as $m) {
        $year  = $m['year'];
        $month = str_pad($m['month'], 2, '0', STR_PAD_LEFT);

        $start = new DateTime("$year-$month-01");
        $end   = (clone $start)->modify('last day of this month');

        while ($start <= $end) {
            $dates[] = $start->format('Y-m-d');
            $start->modify('+1 day');
        }
    }
    return $dates;
}

/**
 * Genera array de fechas 'YYYY-MM-DD' entre $start y $end (inclusive)
 */
function getDateRangeBetween($start, $end)
{
    $dates = [];
    $current = new DateTime($start);
    $stop    = new DateTime($end);
    while ($current <= $stop) {
        $dates[] = $current->format('Y-m-d');
        $current->modify('+1 day');
    }
    return $dates;
}

/**
 * Crea (si no existe) y obtiene entradas de calendario en las fechas
 */
function getOrCreateCalendarEntries($userId, $dates)
{
    $pdo = getDBConnection();
    foreach ($dates as $d) {
        $check = $pdo->prepare("SELECT 1 FROM calendars WHERE user_id=:u AND date=:d");
        $check->execute([':u' => $userId, ':d' => $d]);
        if (!$check->fetchColumn()) {
            $ins = $pdo->prepare("
                INSERT INTO calendars (user_id, date, hours)
                VALUES (:u, :d, 0)
            ");
            $ins->execute([':u' => $userId, ':d' => $d]);
        }
    }

    $in = implode(',', array_fill(0, count($dates), '?'));
    $params = array_merge([$userId], $dates);
    $sql = "SELECT date, hours FROM calendars WHERE user_id=? AND date IN ($in)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($rows as $r) {
        $result[$r['date']] = $r['hours'];
    }
    return $result;
}

/**
 * Actualizar horas
 */
function updateCalendar($userId, $hoursByDate)
{
    $pdo = getDBConnection();
    $pdo->beginTransaction();
    try {
        foreach ($hoursByDate as $date => $hours) {
            // Convertir a float (admite coma/punto)
            $val = floatval(str_replace(',', '.', $hours));
            $stmt = $pdo->prepare("
                UPDATE calendars
                SET hours=:h
                WHERE user_id=:u AND date=:d
            ");
            $stmt->execute([
                ':h' => $val,
                ':u' => $userId,
                ':d' => $date
            ]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Suma total de horas en las fechas
 */
function getTotalHours($userId, $dates)
{
    if (empty($dates)) return 0;
    $pdo = getDBConnection();
    $in = implode(',', array_fill(0, count($dates), '?'));
    $params = array_merge([$userId], $dates);
    $stmt = $pdo->prepare("SELECT SUM(hours) as total FROM calendars WHERE user_id=? AND date IN ($in)");
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['total'] ?? 0;
}

/**
 * Todos los usuarios que tengan alguna fila en calendars
 */
function getAllUsersWithCalendar()
{
    $pdo = getDBConnection();
    $stmt = $pdo->query("
        SELECT DISTINCT u.id, u.name, u.username
        FROM users u
        JOIN calendars c ON u.id = c.user_id
        ORDER BY u.name
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Obtiene estadísticas del calendario de un usuario:
 * - Primer día con horas != 0
 * - Último día con horas != 0
 * - Total de horas
 */
function getCalendarStatsForUser($userId)
{
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT 
            MIN(date) AS start_day,
            MAX(date) AS end_day,
            SUM(hours) AS total_hours
        FROM calendars
        WHERE user_id = :u AND hours != 0
    ");
    $stmt->execute([':u' => $userId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    return $stats;
}

/**
 * Imprime el calendario mensual en formato de cuadrícula (lunes-domingo).
 * - Resalta en amarillo (class highlight) del 1-jun al 13-jun
 * - Solo editable del 3-mar al 13-jun
 * - Los días con 0 horas se muestran en gris (clase zero), días con >0 horas se muestran en blanco (clase nonzero),
 *   excepto los días de junio dentro de 1-13, que se resaltan en amarillo
 */
function renderMonthlyCalendarGrid($year, $month, $entries, $canEdit)
{
    $daysOfWeek = [1=>'Lun',2=>'Mar',3=>'Mié',4=>'Jue',5=>'Vie',6=>'Sáb',7=>'Dom'];
    $monthNames = [
        1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',
        5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',
        9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'
    ];

    $monthStr = str_pad($month, 2, '0', STR_PAD_LEFT);
    $firstDay   = new DateTime("$year-$monthStr-01");
    $lastDay    = (clone $firstDay)->modify('last day of this month');
    $firstWeekday = (int)$firstDay->format('N'); // 1=Lunes, 7=Domingo
    $totalDays  = (int)$lastDay->format('j');

    echo '<h3>'.$monthNames[$month].' '.$year.'</h3>';
    echo '<table class="calendar-grid">';
    echo '<thead><tr>';
    foreach ($daysOfWeek as $dw) {
        echo '<th>'.$dw.'</th>';
    }
    echo '</tr></thead>';
    echo '<tbody>';

    $currentDay = 1;
    while ($currentDay <= $totalDays) {
        echo '<tr>';
        for ($col = 1; $col <= 7; $col++) {
            if (($col < $firstWeekday && $currentDay == 1) || ($currentDay > $totalDays)) {
                // Celda vacía
                echo '<td class="empty"></td>';
            } else {
                $dayString = str_pad($currentDay, 2, '0', STR_PAD_LEFT);
                $dateStr   = "$year-$monthStr-$dayString";
                $hours     = $entries[$dateStr] ?? 0;

                // Determinar la clase
                if ($dateStr >= '2025-06-01' && $dateStr <= '2025-06-13') {
                    $classExtra = ' highlight';
                } else {
                    if (floatval($hours) == 0) {
                        $classExtra = ' zero';
                    } else {
                        $classExtra = ' nonzero';
                    }
                }

                echo '<td class="'.$classExtra.'">';
                echo '<div class="day-number">'.$currentDay.'</div>';

                // Check rango editable
                $withinRange = ($dateStr >= START_DATE && $dateStr <= END_DATE);
                if ($canEdit && $withinRange) {
                    echo '<input '
                         .'type="text" '
                         .'class="hours-input" '
                         .'name="hours_'.$dateStr.'" '
                         .'value="'.$hours.'" '
                         .'size="2" '
                         .'title="Introduzca el número de horas trabajadas este día" '
                         .'/>';
                } else {
                    echo '<div class="hours-display" title="Horas registradas: '.$hours.'">'.$hours.'</div>';
                }

                echo '</td>';
                $currentDay++;
            }
        }
        echo '</tr>';
        $firstWeekday = 1;
    }
    echo '</tbody>';
    echo '</table>';
}

// -------------------------------------------------------------------
//                          RUTAS
// -------------------------------------------------------------------

$action = $_GET['action'] ?? '';

// 1. Logout
if ($action === 'logout') {
    logoutUser();
    header('Location: index.php');
    exit;
}

// 2. Register
if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($name && $email && $username && $password) {
        if (registerUser($name, $email, $username, $password)) {
            // Iniciar sesión al registrar
            loginUser($username, $password);
            header('Location: index.php');
            exit;
        } else {
            $error = "No se pudo registrar (usuario ya existe o error).";
        }
    } else {
        $error = "Todos los campos son obligatorios.";
    }
}

// 3. Login
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (loginUser($username, $password)) {
        header('Location: index.php');
        exit;
    } else {
        $error = "Usuario o contraseña inválidos.";
    }
}

// 4. Guardar horas
if ($action === 'save' && isLoggedIn() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $hoursData = [];
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'hours_') === 0) {
            $date = substr($key, 6);
            $hoursData[$date] = $value;
        }
    }
    updateCalendar($_SESSION['user_id'], $hoursData);
    // Redirigir con mensaje de éxito
    header('Location: index.php?msg=saved');
    exit;
}

function isAdmin()
{
    return (isLoggedIn() && isset($_SESSION['username']) && $_SESSION['username'] === 'jocarsa');
}

// -------------------------------------------------------------------
//        NUEVAS RUTAS ESPECÍFICAS PARA ADMINISTRADOR
// -------------------------------------------------------------------

// 5. Listado de usuarios (por defecto para admin)
if ($action === 'list_users' && isAdmin()) {
    // Simplemente cargará la vista de la lista de usuarios
}

// 6. Ver calendario de un usuario (admin)
if ($action === 'view_calendar' && isAdmin() && isset($_GET['user_id'])) {
    $viewUserId = (int)$_GET['user_id'];
}

// 7. Ver resumen de todos los usuarios (tabla con días en columnas)
if ($action === 'all_users_summary' && isAdmin()) {
    // Lógica se encuentra más abajo en la sección HTML
}

// -------------------------------------------------------------------
//              PLANTILLA HTML PRINCIPAL
// -------------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>jocarsa | khaki</title>
    <link rel="stylesheet" href="style.css">
    <link rel="icon" type="image/svg+xml" href="khaki.png" />
</head>
<body>
<h1><img src="khaki.png">jocarsa | khaki</h1>

<?php if (!isLoggedIn()): ?>

    <!-- ====================== NO LOGUEADO: LOGIN / REGISTER ======================= -->
    <div class="container login">
        <?php
        $mode = $_GET['mode'] ?? 'login';
        if ($mode === 'register'):
        ?>
            <h2>Registro de Usuario</h2>
            <?php if (!empty($error)): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form action="index.php?action=register" method="POST">
                <label>Nombre</label>
                <input 
                    type="text" 
                    name="name" 
                    required 
                    title="Escriba su nombre completo"
                >

                <label>Email</label>
                <input 
                    type="email" 
                    name="email" 
                    required 
                    title="Escriba su dirección de correo electrónico"
                >

                <label>Usuario</label>
                <input 
                    type="text" 
                    name="username" 
                    required 
                    title="Escriba el nombre de usuario que desea"
                >

                <label>Contraseña</label>
                <input 
                    type="password" 
                    name="password" 
                    required 
                    title="Cree una contraseña segura"
                >

                <button type="submit" title="Crear una nueva cuenta con estos datos">Registrarse</button>
            </form>
            <p>
                <a 
                    href="index.php?mode=login" 
                    title="Cambiar al formulario de inicio de sesión"
                >
                    ¿Ya tienes cuenta? Inicia sesión
                </a>
            </p>

        <?php else: ?>
            <h2>Iniciar Sesión</h2>
            <?php if (!empty($error)): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form action="index.php?action=login" method="POST">
                <label>Usuario</label>
                <input 
                    type="text" 
                    name="username" 
                    required 
                    title="Ingrese su nombre de usuario registrado"
                >

                <label>Contraseña</label>
                <input 
                    type="password" 
                    name="password" 
                    required 
                    title="Ingrese la contraseña de su cuenta"
                >

                <button type="submit" title="Iniciar sesión con los datos proporcionados">Entrar</button>
            </form>
            <p>
                <a 
                    href="index.php?mode=register" 
                    title="Cambiar al formulario de registro"
                >
                    ¿No tienes cuenta? Regístrate
                </a>
            </p>
        <?php endif; ?>
    </div>

<?php else: ?>

    <!-- ====================== USUARIO CON SESIÓN ======================= -->
    <div class="container">
        <div class="top-bar">
            <div>
                <strong>Sesión iniciada como:</strong> 
                <?= htmlspecialchars($_SESSION['name']) ?> (<?= htmlspecialchars($_SESSION['username']) ?>)
            </div>
            <div>
                <a 
                    href="index.php?action=logout" 
                    class="logout" 
                    title="Cerrar la sesión actual"
                >
                    Cerrar Sesión
                </a>
            </div>
        </div>

        <?php if (isAdmin()): ?>
            <!-- Admin: mostrar enlace para la tabla global, enlace para la lista de usuarios -->
            <p>
                <a 
                    href="index.php?action=list_users"
                    title="Ver la lista de todos los usuarios con calendarios"
                >
                    Lista de Usuarios
                </a> 
                | 
                <a 
                    href="index.php?action=all_users_summary"
                    title="Ver el resumen de calendarios de todos los usuarios"
                >
                    Ver Calendario (Todos los usuarios)
                </a>
            </p>

            <?php
            // 1) Si no se especificó 'view_calendar' ni 'all_users_summary', o se pidió 'list_users',
            //    mostramos la tabla con todos los usuarios y stats.
            if (
                ($action === '' || $action === null) 
                || $action === 'list_users'
            ) {
                ?>
                <h3>Calendarios de Usuarios</h3>
                <table class="user-list">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Usuario</th>
                            <th>Día inicio (con horas)</th>
                            <th>Día fin (con horas)</th>
                            <th>Total horas</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $users = getAllUsersWithCalendar();
                        foreach ($users as $u) {
                            $stats = getCalendarStatsForUser($u['id']);
                            echo '<tr>';
                            echo '<td>'.htmlspecialchars($u['name']).'</td>';
                            echo '<td>'.htmlspecialchars($u['username']).'</td>';
                            echo '<td>' . ($stats['start_day'] ?? 'N/A') . '</td>';
                            echo '<td>' . ($stats['end_day'] ?? 'N/A') . '</td>';
                            echo '<td>' . ($stats['total_hours'] ?? 0) . '</td>';
                            echo '<td>'
                                 .'<a href="index.php?action=view_calendar&user_id='.$u['id']
                                 .'" title="Ver el calendario detallado de este usuario">'
                                 .'View Calendar</a></td>';
                            echo '</tr>';
                        }
                        ?>
                    </tbody>
                </table>
                <?php
            }

            // 2) Si action = 'view_calendar', se muestra el calendario detallado de ese usuario:
            if ($action === 'view_calendar' && isset($viewUserId)) {
                // Meses de marzo a junio
                $monthsToShow = [
                    ['year' => 2025, 'month' => 3],
                    ['year' => 2025, 'month' => 4],
                    ['year' => 2025, 'month' => 5],
                    ['year' => 2025, 'month' => 6],
                ];
                // Generar todas las fechas
                $allDates = getDateRange($monthsToShow);
                // Crear/recuperar registros
                $entries = getOrCreateCalendarEntries($viewUserId, $allDates);
                // Calcular total
                $totalHours = getTotalHours($viewUserId, $allDates);
                // El admin puede editar
                $canEdit = true;
                ?>
                <h2>Calendario del usuario #<?= $viewUserId ?></h2>
                <div class="total-hours">
                    Total de horas: <span id="totalHoursSpan"><?= $totalHours ?></span>
                </div>
                <?php if (isset($_GET['msg']) && $_GET['msg'] === 'saved'): ?>
                    <div class="success">¡Calendario guardado con éxito!</div>
                <?php endif; ?>

                <form action="index.php?action=save" method="POST">
                    <?php
                    // Asignar temporalmente la variable de sesión para updateCalendar:
                    $_SESSION['user_id'] = $viewUserId;
                    foreach ($monthsToShow as $m) {
                        renderMonthlyCalendarGrid($m['year'], $m['month'], $entries, $canEdit);
                    }
                    ?>
                    <button 
                        type="submit" 
                        title="Guardar los cambios realizados en las horas de este usuario"
                    >
                        Guardar
                    </button>
                </form>
                <?php
            }

            // 3) Si action = 'all_users_summary', mostramos la tabla con todos los usuarios vs días
            if ($action === 'all_users_summary') {
                $users = getAllUsersWithCalendar();
                // Rango: 1 marzo 2025 al 30 junio 2025
                $dates = getDateRangeBetween('2025-03-01','2025-06-30');

                // Agrupamos las fechas por año-mes (ej: '2025-03' => ['2025-03-01','2025-03-02', ...])
                $yearMonthDays = [];
                foreach ($dates as $d) {
                    $yearMonth = substr($d, 0, 7); // 'YYYY-MM'
                    if (!isset($yearMonthDays[$yearMonth])) {
                        $yearMonthDays[$yearMonth] = [];
                    }
                    $yearMonthDays[$yearMonth][] = $d;
                }

                // Para mostrar el nombre del mes:
                $monthNames = [
                    '01' => 'Enero',
                    '02' => 'Febrero',
                    '03' => 'Marzo',
                    '04' => 'Abril',
                    '05' => 'Mayo',
                    '06' => 'Junio',
                    '07' => 'Julio',
                    '08' => 'Agosto',
                    '09' => 'Septiembre',
                    '10' => 'Octubre',
                    '11' => 'Noviembre',
                    '12' => 'Diciembre'
                ];
                ?>
                <h2>Resumen Mensual (Todos los Usuarios)</h2>
                <table 
                    class="calendar-grid supercalendario" 
                    style="white-space: nowrap; font-size:12px;"
                >
                    <thead>
                        <!-- ============ Fila 1: Nombre de Mes (con colspan para sus días) + "Usuario" ============ -->
                        <tr>
                            <!-- 'Usuario' ocupará dos filas, así que usamos rowspan="2" -->
                            <th rowspan="2">Usuario</th>

                            <?php foreach ($yearMonthDays as $ym => $daysArr): ?>
                                <?php
                                    // $ym es algo como '2025-03'. Separamos año y mes:
                                    $year = substr($ym, 0, 4);
                                    $month = substr($ym, 5, 2);

                                    // Nombre del mes según el array
                                    $monthName = $monthNames[$month] ?? $ym;

                                    // Cantidad de días para ese mes
                                    $colspan = count($daysArr);
                                ?>
                                <th colspan="<?= $colspan ?>"><?= $monthName ?> <?= $year ?></th>
                            <?php endforeach; ?>
                        </tr>

                        <!-- ============ Fila 2: Día del mes para cada fecha en orden global ============ -->
                        <tr>
                            <?php foreach ($dates as $d): ?>
                                <!-- Extraer solo el día (DD) -->
                                <th><?= substr($d, 8, 2) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ($users as $u) {
                            // Crear/recuperar sus registros
                            $entries = getOrCreateCalendarEntries($u['id'], $dates);
                            echo '<tr>';
                            echo '<td style="text-align:left;">'
                                 .'<strong>'.htmlspecialchars($u['name']).'</strong><br>(' 
                                 .htmlspecialchars($u['username']) 
                                 .')</td>';
                            foreach ($dates as $d) {
                                $hours = floatval($entries[$d] ?? 0);
                                $bg = ($hours > 0) ? '#ffffff' : '#e0e0e0';
                                $show = ($hours > 0) ? $hours : '';
                                echo '<td style="background-color:'.$bg.';" '
                                     .'title="Horas: '.($show !== '' ? $show : '0').'">'
                                     .$show
                                     .'</td>';
                            }
                            echo '</tr>';
                        }
                        ?>
                    </tbody>
                </table>
                <?php
            }
            ?>

        <?php else: ?>
            <!-- USUARIO NORMAL (NO ADMIN) -->
            <?php
            // Lógica de ver su propio calendario
            $currentUserId = $_SESSION['user_id'];
            $monthsToShow = [
                ['year' => 2025, 'month' => 3],
                ['year' => 2025, 'month' => 4],
                ['year' => 2025, 'month' => 5],
                ['year' => 2025, 'month' => 6],
            ];
            $allDates = getDateRange($monthsToShow);
            $entries = getOrCreateCalendarEntries($currentUserId, $allDates);
            $totalHours = getTotalHours($currentUserId, $allDates);
            $canEdit = true; // El usuario puede editar su propio calendario
            ?>
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'saved'): ?>
                <div class="success">¡Calendario guardado con éxito!</div>
            <?php endif; ?>
            <div class="total-hours">
                Total de horas: <span id="totalHoursSpan"><?= $totalHours ?></span>
            </div>
            <form action="index.php?action=save" method="POST">
                <?php
                foreach ($monthsToShow as $m) {
                    renderMonthlyCalendarGrid($m['year'], $m['month'], $entries, $canEdit);
                }
                ?>
                <button 
                    type="submit" 
                    title="Guardar los cambios realizados en sus horas"
                >
                    Guardar
                </button>
            </form>
        <?php endif; ?>

    </div><!-- .container -->
<?php endif; ?>

<!-- Script para recalcular total de horas en tiempo real (para la edición normal) -->
<script>
document.addEventListener('DOMContentLoaded', function(){
    function updateTotalHours(){
        let total = 0;
        const inputs = document.querySelectorAll('.hours-input');
        inputs.forEach(input => {
            let val = parseFloat(input.value.replace(',', '.'));
            if (!isNaN(val)) {
                total += val;
            }
        });
        const span = document.getElementById('totalHoursSpan');
        if (span) {
            span.textContent = total;
        }
    }

    const inputs = document.querySelectorAll('.hours-input');
    inputs.forEach(input => {
        input.addEventListener('input', updateTotalHours);
    });

    // Calcular al cargar
    updateTotalHours();
});
</script>

<!-- Analytics (opcional) -->
<script src="https://ghostwhite.jocarsa.com/analytics.js?user=khaki.jocarsa.com"></script>
<link rel="stylesheet" href="https://jocarsa.github.io/jocarsa-pink/jocarsa%20%7C%20pink.css">
<script src="https://jocarsa.github.io/jocarsa-pink/jocarsa%20%7C%20pink.js"></script>
</body>
</html>

