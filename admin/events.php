<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check permissions
if (!is_logged_in() || !can_edit_events()) {
    redirect('dashboard.php');
}

$db = getDBConnection();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $id = $_POST['id'] ?? null;
        $title = sanitize_input($_POST['title']);
        $description = $_POST['description'];
        $event_date = $_POST['event_date'];
        $end_date = $_POST['end_date'] ?? null;
        $location = sanitize_input($_POST['location']);
        $status = $_POST['status'];
        $published = isset($_POST['published']) ? 1 : 0;
        
        if ($action === 'add') {
            $stmt = $db->prepare("INSERT INTO events (title, description, event_date, end_date, location, status, published, created_by) 
                                   VALUES (:title, :description, :event_date, :end_date, :location, :status, :published, :user_id)");
            $stmt->execute([
                'title' => $title,
                'description' => $description,
                'event_date' => $event_date,
                'end_date' => $end_date,
                'location' => $location,
                'status' => $status,
                'published' => $published,
                'user_id' => $_SESSION['user_id']
            ]);
            $success = "Veranstaltung erfolgreich hinzugefügt";
        } else {
            $stmt = $db->prepare("UPDATE events SET title = :title, description = :description, 
                                   event_date = :event_date, end_date = :end_date, location = :location, 
                                   status = :status, published = :published 
                                   WHERE id = :id");
            $stmt->execute([
                'title' => $title,
                'description' => $description,
                'event_date' => $event_date,
                'end_date' => $end_date,
                'location' => $location,
                'status' => $status,
                'published' => $published,
                'id' => $id
            ]);
            $success = "Veranstaltung erfolgreich aktualisiert";
        }
    } elseif ($action === 'delete') {
        $id = $_POST['id'];
        // Delete associated images first
        $stmt = $db->prepare("SELECT image_url FROM event_images WHERE event_id = :id");
        $stmt->execute(['id' => $id]);
        foreach ($stmt->fetchAll() as $img) {
            delete_image($img['image_url']);
        }
        
        $stmt = $db->prepare("DELETE FROM events WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $success = "Veranstaltung erfolgreich gelöscht";
    }
}

// Get all events
$events = get_events(null, null, false);

$page_title = 'Veranstaltungen verwalten';
include 'includes/header.php';
?>

<?php if (isset($success)): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="alert alert-error"><?php echo $error; ?></div>
<?php endif; ?>

<div class="page-header">
    <h1>Veranstaltungen verwalten</h1>
    <button class="btn btn-primary" onclick="showAddModal()">+ Neue Veranstaltung</button>
</div>

<div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr>
                <th>Datum</th>
                <th>Titel</th>
                <th>Ort</th>
                <th>Status</th>
                <th>Veröffentlicht</th>
                <th>Aktionen</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($events as $event): ?>
                <tr>
                    <td><?php echo format_datetime($event['event_date']); ?></td>
                    <td><?php echo htmlspecialchars($event['title']); ?></td>
                    <td><?php echo htmlspecialchars($event['location']); ?></td>
                    <td>
                        <span class="badge <?php echo $event['status'] === 'upcoming' ? 'badge-success' : 'badge-warning'; ?>">
                            <?php echo $event['status'] === 'upcoming' ? 'Anstehend' : 'Vergangen'; ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge <?php echo $event['published'] ? 'badge-success' : 'badge-warning'; ?>">
                            <?php echo $event['published'] ? 'Ja' : 'Nein'; ?>
                        </span>
                    </td>
                    <td class="actions">
                        <button class="btn btn-sm btn-secondary" onclick='editEvent(<?php echo json_encode($event); ?>)'>Bearbeiten</button>
                        <button class="btn btn-sm btn-danger" onclick="deleteEvent(<?php echo $event['id']; ?>)">Löschen</button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Add/Edit Modal -->
<div id="eventModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal()">&times;</span>
        <h2 id="modalTitle">Veranstaltung hinzufügen</h2>
        <form method="POST" action="">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="eventId">
            
            <div class="form-group">
                <label for="title">Titel *</label>
                <input type="text" id="title" name="title" required>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="event_date">Startdatum und Uhrzeit *</label>
                    <input type="datetime-local" id="event_date" name="event_date" required>
                </div>
                
                <div class="form-group">
                    <label for="end_date">Enddatum und Uhrzeit</label>
                    <input type="datetime-local" id="end_date" name="end_date">
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="location">Ort</label>
                    <input type="text" id="location" name="location">
                </div>
                
                <div class="form-group">
                    <label for="status">Status *</label>
                    <select id="status" name="status" required>
                        <option value="upcoming">Anstehend</option>
                        <option value="past">Vergangen</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label for="description">Beschreibung</label>
                <textarea id="description" name="description" rows="5"></textarea>
            </div>
            
            <div class="form-group checkbox">
                <label>
                    <input type="checkbox" id="published" name="published">
                    Veröffentlichen
                </label>
            </div>
            
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Speichern</button>
            </div>
        </form>
    </div>
</div>

<script>
function showAddModal() {
    document.getElementById('modalTitle').textContent = 'Veranstaltung hinzufügen';
    document.getElementById('formAction').value = 'add';
    document.getElementById('eventId').value = '';
    document.getElementById('title').value = '';
    document.getElementById('event_date').value = '';
    document.getElementById('end_date').value = '';
    document.getElementById('location').value = '';
    document.getElementById('status').value = 'upcoming';
    document.getElementById('description').value = '';
    document.getElementById('published').checked = false;
    document.getElementById('eventModal').style.display = 'block';
}

function editEvent(event) {
    document.getElementById('modalTitle').textContent = 'Veranstaltung bearbeiten';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('eventId').value = event.id;
    document.getElementById('title').value = event.title;
    document.getElementById('event_date').value = event.event_date.replace(' ', 'T');
    document.getElementById('end_date').value = event.end_date ? event.end_date.replace(' ', 'T') : '';
    document.getElementById('location').value = event.location || '';
    document.getElementById('status').value = event.status;
    document.getElementById('description').value = event.description || '';
    document.getElementById('published').checked = event.published == 1;
    document.getElementById('eventModal').style.display = 'block';
}

function closeModal() {
    document.getElementById('eventModal').style.display = 'none';
}

function deleteEvent(id) {
    if (confirm('Sind Sie sicher, dass Sie diese Veranstaltung löschen möchten?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' + id + '">';
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php include 'includes/footer.php'; ?>
