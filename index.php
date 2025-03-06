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
        // <-- Importante para que sea '03' en vez de '3'
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
 * - Total de horas (sólo de días con horas != 0)
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
 * - Los días con 0 horas se muestran en gris (clase zero) y los días con horas != 0 en blanco (clase nonzero), excepto los días de junio resaltados
 */
function renderMonthlyCalendarGrid($year, $month, $entries, $canEdit)
{
    $daysOfWeek = [1=>'Lun',2=>'Mar',3=>'Mié',4=>'Jue',5=>'Vie',6=>'Sáb',7=>'Dom'];
    $monthNames = [
        1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',
        5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',
        9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'
    ];

    // Aseguramos que el mes sea con cero a la izquierda (ej '03')
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
                // Día con cero a la izquierda
                $dayString = str_pad($currentDay, 2, '0', STR_PAD_LEFT);
                $dateStr   = "$year-$monthStr-$dayString";
                $hours     = $entries[$dateStr] ?? 0;

                // Determinar la clase de fondo
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

                $withinRange = ($dateStr >= START_DATE && $dateStr <= END_DATE);
                if ($canEdit && $withinRange) {
                    echo '<input type="text" class="hours-input" name="hours_'.$dateStr.'" value="'.$hours.'" size="2" />';
                } else {
                    echo '<div class="hours-display">'.$hours.'</div>';
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

/**
 * =========================
 *      Enrutamiento
 * =========================
 */
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

// 5. Admin (jocarsa) quiere ver calendario de otro
$viewUserId = null;
if (isLoggedIn() && $_SESSION['username'] === 'jocarsa' && isset($_GET['view_user'])) {
    $viewUserId = (int)$_GET['view_user'];
}

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
<div class="container login">
    <?php
    // Mostrar login o registro
    $mode = $_GET['mode'] ?? 'login';
    if ($mode === 'register'):
    ?>
        <h2>Registro de Usuario</h2>
        <?php if (!empty($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form action="index.php?action=register" method="POST">
            <label>Nombre</label>
            <input type="text" name="name" required>

            <label>Email</label>
            <input type="email" name="email" required>

            <label>Usuario</label>
            <input type="text" name="username" required>

            <label>Contraseña</label>
            <input type="password" name="password" required>

            <button type="submit">Registrarse</button>
        </form>
        <p><a href="index.php?mode=login">¿Ya tienes cuenta? Inicia sesión</a></p>
    <?php else: ?>
        <h2>Iniciar Sesión</h2>
        <?php if (!empty($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form action="index.php?action=login" method="POST">
            <label>Usuario</label>
            <input type="text" name="username" required>

            <label>Contraseña</label>
            <input type="password" name="password" required>

            <button type="submit">Entrar</button>
        </form>
        <p><a href="index.php?mode=register">¿No tienes cuenta? Regístrate</a></p>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="container">
    <?php
    // Usuario con sesión
    $currentUserId = $_SESSION['user_id'];
    $isAdmin       = ($_SESSION['username'] === 'jocarsa');

    // Determinar a qué usuario se va a mostrar
    $userIdToShow = $currentUserId;
    if ($isAdmin && $viewUserId) {
        $userIdToShow = $viewUserId;
    }

    // Meses de marzo a junio
    $monthsToShow = [
        ['year' => 2025, 'month' => 3],
        ['year' => 2025, 'month' => 4],
        ['year' => 2025, 'month' => 5],
        ['year' => 2025, 'month' => 6],
    ];

    // Generar todas las fechas de esos meses
    $allDates = getDateRange($monthsToShow);
    // Crear/recuperar registros
    $entries = getOrCreateCalendarEntries($userIdToShow, $allDates);
    // Calcular total
    $totalHours = getTotalHours($userIdToShow, $allDates);
    // Se puede editar si es su calendario o si es admin
    $canEdit = ($userIdToShow === $currentUserId) || $isAdmin;
    ?>
    <div class="top-bar">
        <div>
            <strong>Sesión iniciada como:</strong> <?= htmlspecialchars($_SESSION['name']) ?> (<?= htmlspecialchars($_SESSION['username']) ?>)
        </div>
        <div>
            <a href="index.php?action=logout" class="logout">Cerrar Sesión</a>
        </div>
    </div>

    <?php if ($isAdmin): ?>
        <h3>Ver calendarios de otros usuarios</h3>
        <table class="user-list">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Usuario</th>
                    <th>Día inicio</th>
                    <th>Día fin</th>
                    <th>Total horas</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $users = getAllUsersWithCalendar();
                foreach ($users as $u) {
                    $stats = getCalendarStatsForUser($u['id']);
                    echo '<tr>';
                    echo '<td><a href="index.php?view_user='.$u['id'].'">' . htmlspecialchars($u['name']) . '</a></td>';
                    echo '<td>'.htmlspecialchars($u['username']).'</td>';
                    echo '<td>' . ($stats['start_day'] ?? 'N/A') . '</td>';
                    echo '<td>' . ($stats['end_day'] ?? 'N/A') . '</td>';
                    echo '<td>' . ($stats['total_hours'] ?? 0) . '</td>';
                    echo '</tr>';
                }
                ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($userIdToShow !== $currentUserId): ?>
        <p><em>Viendo el calendario del usuario #<?= $userIdToShow ?> (Modo Admin)</em></p>
    <?php endif; ?>

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'saved'): ?>
        <div class="success">¡Calendario guardado con éxito!</div>
    <?php endif; ?>

    <div class="total-hours">
        Total de horas: <span id="totalHoursSpan"><?= $totalHours ?></span>
    </div>

    <!-- Solo ponemos formulario si puede editar -->
    <?php if ($canEdit): ?>
    <form action="index.php?action=save" method="POST">
    <?php endif; ?>

    <?php
    // Renderizar cada mes
    foreach ($monthsToShow as $m) {
        renderMonthlyCalendarGrid($m['year'], $m['month'], $entries, $canEdit);
    }
    ?>

    <?php if ($canEdit): ?>
        <button type="submit">Guardar</button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>
</div>

<!-- Script para recalcular total de horas en tiempo real -->
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
        document.getElementById('totalHoursSpan').textContent = total;
    }

    const inputs = document.querySelectorAll('.hours-input');
    inputs.forEach(input => {
        input.addEventListener('input', updateTotalHours);
    });

    // Calcular al cargar
    updateTotalHours();
});
</script>
<script src="https://ghostwhite.jocarsa.com/analytics.js?user=khaki.jocarsa.com"></script>
</body>
</html>

