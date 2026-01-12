<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is admin
if (!is_logged_in() || !has_role('admin')) {
    redirect('dashboard.php');
}

$db = getDBConnection();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $section = $_POST['section'] ?? '';
    
    if ($section === 'social_media') {
        $action = $_POST['action'];
        
        if ($action === 'update') {
            $id = $_POST['id'];
            $platform = sanitize_input($_POST['platform']);
            $url = sanitize_input($_POST['url']);
            $icon_class = sanitize_input($_POST['icon_class']);
            $active = isset($_POST['active']) ? 1 : 0;
            $sort_order = (int)$_POST['sort_order'];
            
            $stmt = $db->prepare("UPDATE social_media SET platform = :platform, url = :url, 
                                   icon_class = :icon, active = :active, sort_order = :sort 
                                   WHERE id = :id");
            $stmt->execute([
                'platform' => $platform,
                'url' => $url,
                'icon' => $icon_class,
                'active' => $active,
                'sort' => $sort_order,
                'id' => $id
            ]);
            $success = "Social Media Link aktualisiert";
        } elseif ($action === 'add') {
            $platform = sanitize_input($_POST['platform']);
            $url = sanitize_input($_POST['url']);
            $icon_class = sanitize_input($_POST['icon_class']);
            $sort_order = (int)$_POST['sort_order'];
            
            $stmt = $db->prepare("INSERT INTO social_media (platform, url, icon_class, sort_order) 
                                   VALUES (:platform, :url, :icon, :sort)");
            $stmt->execute([
                'platform' => $platform,
                'url' => $url,
                'icon' => $icon_class,
                'sort' => $sort_order
            ]);
            $success = "Social Media Link hinzugefügt";
        } elseif ($action === 'delete') {
            $id = $_POST['id'];
            $stmt = $db->prepare("DELETE FROM social_media WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $success = "Social Media Link gelöscht";
        }
    } elseif ($section === 'users') {
        $action = $_POST['action'];
        
        if ($action === 'add') {
            $username = sanitize_input($_POST['username']);
            $email = sanitize_input($_POST['email']);
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $role = $_POST['role'];
            $first_name = sanitize_input($_POST['first_name']);
            $last_name = sanitize_input($_POST['last_name']);
            
            $stmt = $db->prepare("INSERT INTO users (username, email, password, role, first_name, last_name) 
                                   VALUES (:username, :email, :password, :role, :first_name, :last_name)");
            $stmt->execute([
                'username' => $username,
                'email' => $email,
                'password' => $password,
                'role' => $role,
                'first_name' => $first_name,
                'last_name' => $last_name
            ]);
            $success = "Benutzer erfolgreich hinzugefügt";
        } elseif ($action === 'delete' && $_POST['id'] != $_SESSION['user_id']) {
            $id = $_POST['id'];
            $stmt = $db->prepare("DELETE FROM users WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $success = "Benutzer gelöscht";
        }
    } elseif ($section === 'transaction_categories') {
        $action = $_POST['action'];
        
        if ($action === 'update') {
            $id = $_POST['id'];
            $name = sanitize_input($_POST['name']);
            $description = $_POST['description'];
            $color = $_POST['color'];
            $icon = sanitize_input($_POST['icon']);
            $active = isset($_POST['active']) ? 1 : 0;
            $sort_order = (int)$_POST['sort_order'];
            
            $stmt = $db->prepare("UPDATE transaction_categories SET name = :name, description = :description, 
                                   color = :color, icon = :icon, active = :active, sort_order = :sort 
                                   WHERE id = :id");
            $stmt->execute([
                'name' => $name,
                'description' => $description,
                'color' => $color,
                'icon' => $icon,
                'active' => $active,
                'sort' => $sort_order,
                'id' => $id
            ]);
            $success = "Kategorie aktualisiert";
        } elseif ($action === 'add') {
            $name = sanitize_input($_POST['name']);
            $description = $_POST['description'];
            $color = $_POST['color'];
            $icon = sanitize_input($_POST['icon']);
            $sort_order = (int)$_POST['sort_order'];
            
            $stmt = $db->prepare("INSERT INTO transaction_categories (name, description, color, icon, sort_order) 
                                   VALUES (:name, :description, :color, :icon, :sort)");
            $stmt->execute([
                'name' => $name,
                'description' => $description,
                'color' => $color,
                'icon' => $icon,
                'sort' => $sort_order
            ]);
            $success = "Kategorie hinzugefügt";
        } elseif ($action === 'delete') {
            $id = $_POST['id'];
            $stmt = $db->prepare("DELETE FROM transaction_categories WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $success = "Kategorie gelöscht";
        }
    }
}

// Get social media links
$stmt = $db->query("SELECT * FROM social_media ORDER BY sort_order");
$social_links = $stmt->fetchAll();

// Get transaction categories
$stmt = $db->query("SELECT * FROM transaction_categories ORDER BY sort_order");
$categories = $stmt->fetchAll();

// Get users
$stmt = $db->query("SELECT * FROM users ORDER BY created_at DESC");
$users = $stmt->fetchAll();

$page_title = 'Einstellungen';
include 'includes/header.php';
?>

<?php if (isset($success)): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<div class="page-header">
    <h1>Einstellungen</h1>
</div>

<div class="settings-tabs">
    <button class="tab-btn active" onclick="showTab('social')">Social Media</button>
    <button class="tab-btn" onclick="showTab('categories')">Kategorien</button>
    <button class="tab-btn" onclick="showTab('users')">Benutzer</button>
</div>

<!-- Social Media Tab -->
<div id="social-tab" class="tab-content active">
    <h2>Social Media Links</h2>
    
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Sortierung</th>
                    <th>Plattform</th>
                    <th>URL</th>
                    <th>Icon-Klasse</th>
                    <th>Status</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($social_links as $link): ?>
                    <tr>
                        <form method="POST">
                            <input type="hidden" name="section" value="social_media">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?php echo $link['id']; ?>">
                            <td><input type="number" name="sort_order" value="<?php echo $link['sort_order']; ?>" style="width: 60px;"></td>
                            <td><input type="text" name="platform" value="<?php echo htmlspecialchars($link['platform']); ?>"></td>
                            <td><input type="url" name="url" value="<?php echo htmlspecialchars($link['url']); ?>"></td>
                            <td><input type="text" name="icon_class" value="<?php echo htmlspecialchars($link['icon_class']); ?>"></td>
                            <td><input type="checkbox" name="active" <?php echo $link['active'] ? 'checked' : ''; ?>></td>
                            <td class="actions">
                                <button type="submit" class="btn btn-sm btn-primary">Speichern</button>
                                <button type="button" class="btn btn-sm btn-danger" onclick="deleteSocialMedia(<?php echo $link['id']; ?>)">Löschen</button>
                            </td>
                        </form>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <h3>Neuen Link hinzufügen</h3>
    <form method="POST" class="add-form">
        <input type="hidden" name="section" value="social_media">
        <input type="hidden" name="action" value="add">
        <div class="form-row">
            <input type="text" name="platform" placeholder="Plattform (z.B. Instagram)" required>
            <input type="url" name="url" placeholder="https://..." required>
            <input type="text" name="icon_class" placeholder="fab fa-instagram" required>
            <input type="number" name="sort_order" placeholder="Sortierung" value="10" style="width: 100px;">
            <button type="submit" class="btn btn-primary">Hinzufügen</button>
        </div>
    </form>
</div>

<!-- Categories Tab -->
<div id="categories-tab" class="tab-content">
    <h2>Transaktionskategorien verwalten</h2>
    
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Sortierung</th>
                    <th>Farbe</th>
                    <th>Name</th>
                    <th>Beschreibung</th>
                    <th>Icon</th>
                    <th>Status</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categories as $category): ?>
                    <tr>
                        <form method="POST">
                            <input type="hidden" name="section" value="transaction_categories">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?php echo $category['id']; ?>">
                            <td><input type="number" name="sort_order" value="<?php echo $category['sort_order']; ?>" style="width: 60px;"></td>
                            <td>
                                <input type="color" name="color" value="<?php echo htmlspecialchars($category['color']); ?>" style="width: 60px; height: 40px; cursor: pointer;">
                            </td>
                            <td><input type="text" name="name" value="<?php echo htmlspecialchars($category['name']); ?>"></td>
                            <td><input type="text" name="description" value="<?php echo htmlspecialchars($category['description']); ?>"></td>
                            <td><input type="text" name="icon" value="<?php echo htmlspecialchars($category['icon']); ?>" placeholder="fas fa-..."></td>
                            <td><input type="checkbox" name="active" <?php echo $category['active'] ? 'checked' : ''; ?>></td>
                            <td class="actions">
                                <button type="submit" class="btn btn-sm btn-primary">Speichern</button>
                                <button type="button" class="btn btn-sm btn-danger" onclick="deleteCategory(<?php echo $category['id']; ?>)">Löschen</button>
                            </td>
                        </form>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <h3>Neue Kategorie hinzufügen</h3>
    <form method="POST" class="add-form">
        <input type="hidden" name="section" value="transaction_categories">
        <input type="hidden" name="action" value="add">
        <div class="form-row">
            <input type="text" name="name" placeholder="Kategoriename" required style="flex: 1;">
            <input type="text" name="description" placeholder="Beschreibung" style="flex: 2;">
            <input type="color" name="color" value="#1976d2" style="width: 70px;">
            <input type="text" name="icon" placeholder="fas fa-..." style="flex: 1;">
            <input type="number" name="sort_order" placeholder="Sortierung" value="10" style="width: 100px;">
            <button type="submit" class="btn btn-primary">Hinzufügen</button>
        </div>
    </form>
</div>

<!-- Users Tab -->
<div id="users-tab" class="tab-content">
    <h2>Benutzer verwalten</h2>
    
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Benutzername</th>
                    <th>Name</th>
                    <th>E-Mail</th>
                    <th>Rolle</th>
                    <th>Letzter Login</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                        <td><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td>
                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                        <td><span class="badge badge-info"><?php echo htmlspecialchars($user['role']); ?></span></td>
                        <td><?php echo $user['last_login'] ? format_datetime($user['last_login']) : 'Nie'; ?></td>
                        <td class="actions">
                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                <button class="btn btn-sm btn-danger" onclick="deleteUser(<?php echo $user['id']; ?>)">Löschen</button>
                            <?php else: ?>
                                <span class="badge badge-success">Aktueller Benutzer</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <h3>Neuen Benutzer hinzufügen</h3>
    <form method="POST" class="user-form">
        <input type="hidden" name="section" value="users">
        <input type="hidden" name="action" value="add">
        
        <div class="form-row">
            <div class="form-group">
                <label>Benutzername *</label>
                <input type="text" name="username" required>
            </div>
            <div class="form-group">
                <label>E-Mail *</label>
                <input type="email" name="email" required>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label>Vorname</label>
                <input type="text" name="first_name">
            </div>
            <div class="form-group">
                <label>Nachname</label>
                <input type="text" name="last_name">
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label>Passwort *</label>
                <input type="password" name="password" required>
            </div>
            <div class="form-group">
                <label>Rolle *</label>
                <select name="role" required>
                    <option value="pr_manager">PR Manager</option>
                    <option value="event_manager">Event Manager</option>
                    <option value="board">Vorstand</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
        </div>
        
        <button type="submit" class="btn btn-primary">Benutzer hinzufügen</button>
    </form>
</div>

<script>
function showTab(tab) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    
    if (tab === 'social') {
        document.getElementById('social-tab').classList.add('active');
        document.querySelectorAll('.tab-btn')[0].classList.add('active');
    } else if (tab === 'categories') {
        document.getElementById('categories-tab').classList.add('active');
        document.querySelectorAll('.tab-btn')[1].classList.add('active');
    } else {
        document.getElementById('users-tab').classList.add('active');
        document.querySelectorAll('.tab-btn')[2].classList.add('active');
    }
}

function deleteSocialMedia(id) {
    if (confirm('Diesen Social Media Link löschen?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="section" value="social_media">' +
                        '<input type="hidden" name="action" value="delete">' +
                        '<input type="hidden" name="id" value="' + id + '">';
        document.body.appendChild(form);
        form.submit();
    }
}

function deleteCategory(id) {
    if (confirm('Diese Kategorie wirklich löschen?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="section" value="transaction_categories">' +
                        '<input type="hidden" name="action" value="delete">' +
                        '<input type="hidden" name="id" value="' + id + '">';
        document.body.appendChild(form);
        form.submit();
    }
}

function deleteUser(id) {
    if (confirm('Diesen Benutzer wirklich löschen?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="section" value="users">' +
                        '<input type="hidden" name="action" value="delete">' +
                        '<input type="hidden" name="id" value="' + id + '">';
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<style>
.settings-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 2rem;
    border-bottom: 2px solid var(--border-color);
}

.tab-btn {
    padding: 0.75rem 1.5rem;
    background: none;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    font-size: 1rem;
    color: var(--text-color);
    transition: all 0.3s;
}

.tab-btn:hover {
    background-color: var(--light-color);
}

.tab-btn.active {
    border-bottom-color: var(--primary-color);
    color: var(--primary-color);
    font-weight: 600;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

.add-form {
    background: var(--light-color);
    padding: 1.5rem;
    border-radius: 8px;
    margin-top: 2rem;
}

.add-form .form-row {
    display: flex;
    gap: 1rem;
    align-items: center;
}

.add-form input {
    flex: 1;
    padding: 0.75rem;
    border: 1px solid var(--border-color);
    border-radius: 4px;
}

.user-form {
    background: white;
    padding: 2rem;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-top: 2rem;
}

.data-table input[type="text"],
.data-table input[type="url"],
.data-table input[type="number"] {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid var(--border-color);
    border-radius: 4px;
}
</style>

<?php include 'includes/footer.php'; ?>
