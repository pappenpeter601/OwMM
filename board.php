<?php
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/functions.php';

$page_title = 'Kommando - ' . get_org_setting('site_name');

$board_members = get_board_members();

include 'includes/header.php';
?>

<section class="page-header-section">
    <div class="container">
        <h1>Unser Kommando</h1>
        <p>Lernen Sie unser Führungsteam kennen</p>
    </div>
</section>

<section class="section">
    <div class="container">
        <?php if (empty($board_members)): ?>
            <div class="empty-state">
                <i class="fas fa-users fa-3x"></i>
                <h3>Keine Informationen verfügbar</h3>
            </div>
        <?php else: ?>
            <div class="board-grid">
                <?php foreach ($board_members as $member): ?>
                    <div class="board-member-card">
                        <div class="member-image">
                            <?php if (!empty($member['image_url'])): ?>
                                <img src="uploads/<?php echo htmlspecialchars($member['image_url']); ?>" 
                                     alt="<?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>">
                            <?php else: ?>
                                <div class="placeholder-image">
                                    <i class="fas fa-user"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="member-info">
                            <h3><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></h3>
                            <p class="member-position"><?php echo htmlspecialchars($member['position']); ?></p>
                            
                            <?php if (!empty($member['bio'])): ?>
                                <p class="member-bio"><?php echo nl2br(htmlspecialchars($member['bio'])); ?></p>
                            <?php endif; ?>
                            
                            <div class="member-contact">
                                <?php if (!empty($member['email'])): ?>
                                    <a href="mailto:<?php echo htmlspecialchars($member['email']); ?>">
                                        <i class="fas fa-envelope"></i> E-Mail
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($member['telephone'])): ?>
                                    <a href="tel:<?php echo htmlspecialchars($member['telephone']); ?>">
                                        <i class="fas fa-phone"></i> <?php echo htmlspecialchars($member['telephone']); ?>
                                    </a>
                                <?php endif; ?>
                                <?php if (!empty($member['mobile'])): ?>
                                    <a href="tel:<?php echo htmlspecialchars($member['mobile']); ?>">
                                        <i class="fas fa-mobile-alt"></i> <?php echo htmlspecialchars($member['mobile']); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php include 'includes/footer.php'; ?>
