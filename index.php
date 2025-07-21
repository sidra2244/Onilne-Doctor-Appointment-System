<?php
session_start();

// Database configuration
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "appointments_db";

// Create database connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Handle review submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_review'])) {
    $doctor_name = $conn->real_escape_string(htmlspecialchars($_POST['review_doctor'] ?? ''));
    $patient_name = $conn->real_escape_string(htmlspecialchars($_POST['review_patient_name'] ?? ''));
    $rating = intval($_POST['rating'] ?? 0);
    $review_text = $conn->real_escape_string(htmlspecialchars($_POST['review_text'] ?? ''));
    
    if (empty($doctor_name) || empty($patient_name) || $rating < 1 || $rating > 5) {
        $_SESSION['review_error'] = "Please fill all required fields and provide a valid rating (1-5 stars).";
    } else {
        $stmt = $conn->prepare("INSERT INTO reviews (doctor_name, patient_name, rating, review_text) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssis", $doctor_name, $patient_name, $rating, $review_text);
        
        if ($stmt->execute()) {
            $_SESSION['review_success'] = "Thank you for your review!";
        } else {
            $_SESSION['review_error'] = "Error submitting review: " . $stmt->error;
        }
        
        $stmt->close();
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle update appointment
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_appointment'])) {
    $appointment_id = intval($_POST['appointment_id']);
    $new_time = $conn->real_escape_string(htmlspecialchars($_POST['new_time'] ?? ''));
    
    if (empty($new_time)) {
        $_SESSION['error'] = "Please select a new time slot";
    } else {
        $stmt = $conn->prepare("UPDATE appointments SET time = ? WHERE id = ?");
        $stmt->bind_param("si", $new_time, $appointment_id);
        
        if ($stmt->execute()) {
            $_SESSION['update_success'] = true;
        } else {
            $_SESSION['error'] = "Error updating appointment: " . $stmt->error;
        }
        $stmt->close();
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle delete appointment
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_appointment'])) {
    $appointment_id = intval($_POST['appointment_id']);
    
    $stmt = $conn->prepare("DELETE FROM appointments WHERE id = ?");
    $stmt->bind_param("i", $appointment_id);
    
    if ($stmt->execute()) {
        $_SESSION['delete_success'] = true;
    } else {
        $_SESSION['error'] = "Error deleting appointment: " . $stmt->error;
    }
    $stmt->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle cancel appointment
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['cancel_appointment'])) {
    $appointment_id = intval($_POST['appointment_id']);
    
    $stmt = $conn->prepare("UPDATE appointments SET is_active = FALSE WHERE id = ?");
    $stmt->bind_param("i", $appointment_id);
    
    if ($stmt->execute()) {
        $_SESSION['cancel_success'] = true;
    } else {
        $_SESSION['error'] = "Error cancelling appointment: " . $stmt->error;
    }
    $stmt->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle appointment booking
if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['update_appointment']) && !isset($_POST['delete_appointment']) && !isset($_POST['cancel_appointment']) && !isset($_POST['submit_review'])) {
    // Sanitize and validate input
    $name = $conn->real_escape_string(htmlspecialchars($_POST['name'] ?? ''));
    $phone = $conn->real_escape_string(htmlspecialchars($_POST['phone'] ?? ''));
    $time = $conn->real_escape_string(htmlspecialchars($_POST['appointmentTime'] ?? ''));
    $doctorName = $conn->real_escape_string(htmlspecialchars($_POST['doctorName'] ?? ''));
    $diseases = $conn->real_escape_string(htmlspecialchars($_POST['diseases'] ?? ''));

    // Validate required fields
    if (empty($name) || empty($phone) || empty($time) || empty($doctorName)) {
        $_SESSION['error'] = "All fields are required!";
        $_SESSION['form_data'] = $_POST;
    } 
    // Validate phone number format (11 digits)
    elseif (!preg_match('/^[0-9]{11}$/', $phone)) {
        $_SESSION['error'] = "Please enter a valid 11-digit phone number (e.g., 03001234567)";
        $_SESSION['form_data'] = $_POST;
    }
    else {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Insert into patients table
            $stmtPatient = $conn->prepare("INSERT INTO patients (name, phone, diseases) VALUES (?, ?, ?)");
            $stmtPatient->bind_param("sss", $name, $phone, $diseases);
            $stmtPatient->execute();
            $patientId = $stmtPatient->insert_id;
            
            // Insert into appointments table
            $stmtAppointment = $conn->prepare("INSERT INTO appointments (name, phone, time, doctor_name, patient_id) VALUES (?, ?, ?, ?, ?)");
            $stmtAppointment->bind_param("ssssi", $name, $phone, $time, $doctorName, $patientId);
            
            if ($stmtAppointment->execute()) {
                $conn->commit();
                $_SESSION['appointment_success'] = true;
                $_SESSION['message'] = "Appointment booked successfully!";
                $_SESSION['booked_doctor'] = $doctorName;
            } else {
                throw new Exception("Error: " . $stmtAppointment->error);
            }
            
            $stmtAppointment->close();
            $stmtPatient->close();
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = $e->getMessage();
            $_SESSION['form_data'] = $_POST;
        }
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Fetch all doctors for review dropdown
$doctors = [];
$result = $conn->query("SELECT name FROM doctors ORDER BY name");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $doctors[] = $row['name'];
    }
    $result->free();
}

// Calculate average ratings for each doctor
if (!isset($doctor_ratings)) {
    $query = "SELECT d.name, d.specialization, 
                     AVG(r.rating) AS avg_rating,
                     COUNT(r.id) AS review_count
              FROM doctors d
              LEFT JOIN reviews r ON d.name = r.doctor_name
              GROUP BY d.name
              HAVING review_count > 0
              ORDER BY avg_rating DESC";
    
    $result = $conn->query($query);
    $doctor_ratings = [];
    if ($result) {
        $doctor_ratings = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }
}

// Get appointments with patient details
if (!isset($appointments)) {
    $query = "SELECT a.id, a.time, a.doctor_name, p.name AS patient_name, p.phone, p.diseases 
              FROM appointments a
              JOIN patients p ON a.patient_id = p.id
              ORDER BY a.time DESC";
    
    $result = $conn->query($query);
    $appointments = [];
    if ($result) {
        $appointments = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }
}

// Get statistics
$stats = [];
$result = $conn->query("SELECT COUNT(*) AS total_appointments FROM appointments");
if ($result) {
    $stats['total_appointments'] = $result->fetch_assoc()['total_appointments'];
    $result->free();
}

$result = $conn->query("SELECT doctor_name, COUNT(*) AS appointment_count 
                       FROM appointments 
                       GROUP BY doctor_name 
                       ORDER BY appointment_count DESC");
if ($result) {
    $stats['doctor_appointments'] = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
}

// Retrieve messages from session
$showSuccessPopup = false;
$successMessage = '';
$bookedDoctor = '';
$errorMessage = '';
$formData = [];

if (isset($_SESSION['appointment_success']) && $_SESSION['appointment_success']) {
    $showSuccessPopup = true;
    $successMessage = $_SESSION['message'] ?? '';
    $bookedDoctor = $_SESSION['booked_doctor'] ?? '';
    unset($_SESSION['appointment_success']);
    unset($_SESSION['message']);
    unset($_SESSION['booked_doctor']);
}

if (isset($_SESSION['error'])) {
    $errorMessage = $_SESSION['error'];
    unset($_SESSION['error']);
}

if (isset($_SESSION['form_data'])) {
    $formData = $_SESSION['form_data'];
    unset($_SESSION['form_data']);
}

// Close database connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MediCare - Doctor Appointment System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #3a86ff;
            --primary-dark: #2667cc;
            --secondary: #8338ec;
            --success: #06d6a0;
            --danger: #ef476f;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --light-gray: #e9ecef;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Poppins', 'Segoe UI', sans-serif;
        }

        body {
            background-color: #f5f7ff;
            color: var(--dark);
            line-height: 1.6;
        }

        /* Header */
        header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 1.5rem 0;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }

        header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path fill="rgba(255,255,255,0.05)" d="M0,0 L100,0 L100,100 L0,100 Z"></path></svg>');
            background-size: cover;
        }

        header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
        }

        header p {
            font-size: 1.1rem;
            opacity: 0.9;
            position: relative;
        }

        /* Main Container */
        .main-container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }

        /* Search Bar */
        .search-container {
            margin: 2rem 0;
            display: flex;
            justify-content: center;
        }

        .search-bar {
            width: 100%;
            max-width: 600px;
            display: flex;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            border-radius: 50px;
            overflow: hidden;
        }

        .search-bar input {
            flex: 1;
            padding: 1rem 1.5rem;
            border: none;
            font-size: 1rem;
            outline: none;
        }

        .search-bar button {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 0 1.5rem;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .search-bar button:hover {
            background-color: var(--primary-dark);
        }

        /* Specializations Grid */
        .specializations {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 2rem;
            margin-top: 2rem;
        }

        /* Doctor Card */
        .card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s, box-shadow 0.3s;
            position: relative;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.12);
        }

        .card-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            background-color: var(--success);
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .card-img {
            width: 100%;
            height: 200px;
            object-fit: cover;
        }

        .card-content {
            padding: 1.5rem;
        }

        .card-content h3 {
            color: var(--primary);
            margin-bottom: 0.5rem;
            font-size: 1.3rem;
        }

        .card-content .specialty {
            color: var(--gray);
            font-size: 0.9rem;
            margin-bottom: 1rem;
            display: block;
        }

        .card-content .rating {
            color: #ffc107;
            margin-bottom: 1rem;
        }

        .card-content .doctor-info {
            margin-bottom: 1.5rem;
        }

        .card-content .doctor-info p {
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .card-content .doctor-info i {
            color: var(--primary);
            margin-right: 0.5rem;
            width: 20px;
            text-align: center;
        }

        .action-buttons {
            display: flex;
            justify-content: space-between;
        }

        .view-btn,
        .book-btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .view-btn {
            background-color: var(--light-gray);
            color: var(--dark);
        }

        .view-btn:hover {
            background-color: #dee2e6;
        }

        .book-btn {
            background-color: var(--primary);
            color: white;
        }

        .book-btn:hover {
            background-color: var(--primary-dark);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            animation: fadeIn 0.3s;
        }

        .modal-content {
            background: white;
            width: 90%;
            max-width: 500px;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            transform: translateY(20px);
            animation: slideUp 0.3s forwards;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes slideUp {
            to {
                transform: translateY(0);
            }
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 1.5rem;
            position: relative;
        }

        .modal-header h3 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .modal-header .close {
            position: absolute;
            top: 1rem;
            right: 1.5rem;
            font-size: 1.5rem;
            cursor: pointer;
            transition: transform 0.3s;
        }

        .modal-header .close:hover {
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
        }

        .form-control {
            width: 100%;
            padding: 0.8rem 1rem;
            border: 1px solid var(--light-gray);
            border-radius: 6px;
            font-size: 1rem;
            transition: border-color 0.3s, box-shadow 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(58, 134, 255, 0.2);
        }

        .submit-btn {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            width: 100%;
            padding: 1rem;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.3s;
        }

        .submit-btn:hover {
            opacity: 0.9;
        }

        .error-message {
            color: var(--danger);
            text-align: center;
            margin-top: 1rem;
            font-weight: 600;
        }

        /* Success Popup */
        .success-popup {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .popup-content {
            background: white;
            width: 90%;
            max-width: 400px;
            border-radius: 12px;
            overflow: hidden;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            animation: popIn 0.4s;
        }

        @keyframes popIn {
            0% {
                transform: scale(0.8);
                opacity: 0;
            }

            80% {
                transform: scale(1.05);
            }

            100% {
                transform: scale(1);
                opacity: 1;
            }
        }

        .popup-icon {
            font-size: 4rem;
            color: var(--success);
            margin: 1.5rem 0;
            animation: bounce 1s;
        }

        @keyframes bounce {

            0%,
            20%,
            50%,
            80%,
            100% {
                transform: translateY(0);
            }

            40% {
                transform: translateY(-20px);
            }

            60% {
                transform: translateY(-10px);
            }
        }

        .popup-content h3 {
            font-size: 1.8rem;
            margin-bottom: 1rem;
            color: var(--success);
        }

        .popup-content p {
            margin-bottom: 1.5rem;
            color: var(--gray);
            padding: 0 1.5rem;
        }

        .popup-close-btn {
            background-color: var(--success);
            color: white;
            border: none;
            padding: 0.8rem 1.5rem;
            border-radius: 6px;
            font-weight: 600;
            margin-bottom: 1.5rem;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .popup-close-btn:hover {
            background-color: #05b38c;
        }

        /* Doctor list styling */
        .doctors-list {
            margin-top: 1rem;
            border-top: 1px solid var(--light-gray);
            padding-top: 1rem;
        }

        .doctor {
            margin-bottom: 1.5rem;
            padding: 1rem;
            background-color: rgba(58, 134, 255, 0.05);
            border-radius: 8px;
        }

        .doctor h4 {
            color: var(--primary-dark);
            margin-bottom: 0.5rem;
        }

        .doctor .rating {
            margin-bottom: 0.5rem;
        }

        .doctor .doctor-info {
            margin-bottom: 0.5rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            header h1 {
                font-size: 2rem;
            }

            .specializations {
                grid-template-columns: 1fr;
            }

            .modal-content {
                width: 95%;
            }
        }
        .star-rating {
    font-size: 24px;
    color: #ffc107;
    margin: 10px 0;
}
.data-section {
    margin: 2rem 0;
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.08);
}

.table-container {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 1rem;
}

th, td {
    padding: 12px 15px;
    text-align: left;
    border-bottom: 1px solid var(--light-gray);
}

th {
    background-color: var(--primary);
    color: white;
    font-weight: 600;
}

tr:hover {
    background-color: rgba(58, 134, 255, 0.05);
}
.cards-container {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1.5rem;
    margin-top: 1rem;
}

.rating-card {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    transition: transform 0.3s;
}

.rating-card:hover {
    transform: translateY(-5px);
}

.rating-display {
    margin: 1rem 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.stars {
    color: #ffc107;
}

.stars .active {
    color: #ffc107;
}

.avg-rating {
    font-weight: 600;
    color: var(--dark);
}

.review-count {
    color: var(--gray);
    font-size: 0.9rem;
}

.view-reviews-btn {
    background: var(--primary);
    color: white;
    border: none;
    padding: 0.6rem 1rem;
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.3s;
}

.view-reviews-btn:hover {
    background: var(--primary-dark);
}

.star-rating i {
    cursor: pointer;
    margin-right: 5px;
}
.stats-cards {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 1.5rem;
    margin: 1.5rem 0;
}

.stat-card {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    text-align: center;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
}

.stat-value {
    font-size: 2.5rem;
    font-weight: 600;
    color: var(--primary);
    margin-bottom: 0.5rem;
}

.stat-label {
    color: #666;
    font-size: 0.9rem;
}

.chart-container {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    margin-top: 2rem;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
}
.cancel-btn {
        padding: 0.4rem 0.8rem;
        border: none;
        border-radius: 4px;
        font-size: 0.9rem;
        cursor: pointer;
        transition: all 0.3s;
        background-color: #ffc107;
        color: #212529;
    }
    
    .cancel-btn:hover {
        background-color: #e0a800;
        transform: translateY(-1px);
    }
    
    .action-buttons {
        display: flex;
        gap: 8px;
    }
    
    .delete-form, .cancel-form {
        margin: 0;
        padding: 0;
    }
.star-rating i:hover,
.star-rating i.active {
    color: #ffc107;
}
    </style>
</head>

<body>
    <header>
        <h1><i class="fas fa-heartbeat"></i> Online Doctors Appointmnent System</h1>
        <p>Book appointments with top specialists in your area</p>
    </header>

    <div class="main-container">
        <div class="search-container">
            <div class="search-bar">
                <input type="text" id="searchInput" placeholder="Search for doctors, specialties...">
                <button id="searchButton"><i class="fas fa-search"></i></button>
            </div>
        </div>

        <div class="specializations">
            <!-- Dentistry Specialization -->
            <div class="card">
                <img src="data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBxMTEhUTEhIVFhUVFRcVFhYVFRUVFhUVFRUWFhUVFRUYHSggGBolHRUVITEhJSkrLi4uFx8zODMtNygtLisBCgoKDg0OGhAQGi0lHx8tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLf/AABEIAJgBSwMBIgACEQEDEQH/xAAcAAABBQEBAQAAAAAAAAAAAAAEAQIDBQYABwj/xAA8EAABAwIEBAQCCAQGAwAAAAABAAIDBBEFEiExBkFRYRMicYGRoQcUQlJiscHRIzJy4RWCosLw8TOS0v/EABsBAAIDAQEBAAAAAAAAAAAAAAECAAMEBQYH/8QALBEAAgIBBAEDAwIHAAAAAAAAAAECEQMEEiExQRNRYQUikTLRFCNCcYGh8P/aAAwDAQACEQMRAD8A1MtTYdFXzVqpKzFCbuO32QoqWQkXJ1K7yw0jyr1W58F42qR1JX2WcEyJp5UssdjwzGyp6u6L3WdoZ1dU0yyzhR0MeXcuR8lwkD0S5oIQMjS02Kyzj5RuxT8Mx3GuG3/iNG2/osHUNXq2OC7SvPKyhBJsuhpp3jpnL1eOstryZ+5DgQtLhVFHPa41VW7DyiqBxicCFVmjfKNGmlXDLeuwiSIXaLt7clTvrDdekcOYkyVuV1iqrjjg52Uz0wvbVzRzHUd1MeukltkLm+mwk98TGHEywE3VLJWmR93FRTEndDEJvVbdi+hGCpIvYZDyKMbiBaNXLNNmcOa7xCdyrXkbKlginZa1eKF2yBcb7qMJ4QSGY5rSFM2QprCpAEshoCtddWWEsu8KuaFf8N0pe8WF1ROVI04420eg4K2zQhauq8zvW6usNoSGi+iyNXf6wQNvMD6LlZpdUd7TQ7sr+ImOezTrr6LKYlhpDCQFty7kukpmOBFlR0bk+DC8P8VugcGyEuj+Lmdx1HZeu4JiTXNa5rgWuFwQdCCvDOKsM8GbQeV2o6X5hWHA3ErqeQRSH+E86fgcefoefxRatWip1dM+gSwPChjZlOiq6DEdtVYSShw7pVKyuUGi1hcCn+CFVYbUa2Ku2FWRdlE04sFlhVVWwGx1stA4ISogui0CLMYzBA515Xlw5C1h7qzbhkIH/iYR/SCpamBzT2UbKghVqVdl221wVdVw9T5s7WWcebSRa/MDl7IyPw2tcCOd7W5WAtfnrf4op1najQ/IoCrdoQR6q2M6KZY0zJcXYI3L4jBodbW1B9OS87FCTq52vv7L0/FZSAbag6EEXOwGh9tlhamMZjvv1T7hNnhlxUTXcByCIkqLMVa52q6WW7bL1Z4b0+izinR9NOqOCXQIlkqVqxOYs1lJMrunmWJo62260WHVgcs+TGbMGZN0aanmRL2Bw1VLFIrGnnWSUDqY8hXYpSm2vxWFxSnLXHovV7BwsdQqPFOH2SHR1uo/ZLilsfwNmisi+TzF8iHllXo7+CYLaudf1Wcx3g90TS+I5gNxzt2V7lGXRRFSh2Z3C8VdDIDfS69e4bxtsrACbgheHysV1w3i7onhpOl1ly4r5Rtw5fDLr6SeDvBJqYR/DcfOB9knmOy86exfSGE1kVRCY5AHMeMrgdd149x3wi6im0uYX3Mbv9ru4+aXFLww5oeUY3KuspnNTS1a0Ynwc1SBMaFI0Jytj2KYJjWo/DKF0sjWDmdewVU5Ui7HBt0gzh/A31L7AWaN3fsvVMIwqKnYA0C/MqHCqRsEYa0ctVM6Qu0C5ObM5vjo72DTKC57JaitVU6j1c+2rla09K0HXUop9OCqNr7NTdcIyBo9dVI2kstIKQdk91I22wSUN6lGPxDDYZW5ZWBw77j0PJZSq4BhLrskcB0JB+drr06qw4OCoa7CpG6sNx0Q5Q8XGXYLBJ4bQ0m9gB8FZ0mKd1mpjIDYsd8ERhsMjnDykDqq6Zdark2dFJ579dVo4XrI0hs4BaGmlVsOzJlVpFq1cWqCORTNerjM0Qywg7qunw0HZXSjcxBxCpNGcfRFqGmhvoVqHRhCzUoPJI4linfZiMRw3MFl5uH35j5V6hUUCCdSH/gQtoPDPG3ypokQxdqrCgiFxovVPJR45YkWWE4BVTWMcLi0/aPlb63O/snYjQS07skzC08uh9DzXseEQ5IIm9I2D/SE3FKGOZpZK0OHfl3B5LGtc1LlcG2f0yMo8Pk8YZMrCirS0qwx/g2SIl0F5Gfd+2P3QdBwrWyC7YSB+IhvyK2rUY3G7OVLQ5VKlF2aOhxEOG6tIqkDms/ScEV3PI3/ADn9ArOHheWNwMkua3IXsqHPFJ8SNMcepivuiXkFSTsjI47hQ0jWgWCJzWWeT54N0FxyRyjSyCmb5SjZHIaUWaSjBizVnk+PUobM62xN0A2lcdmk+gJXsFA+DLctZfmSBdFf4lC0aFo9LKrLn+5qjRhwfYm2YThOrqIyGmKQjrkdYj4L0meljrKd0M7TlcNCRYtPIi+xCqqjiGNuzrpo4oiWaUm3dGqMUlTPMsW4CrYnvDYTIxpNnsLTmbyIbe/yVJWYJURNzywSsbtmfG5rbnYXIXszuLmA6BZmq44mubzaA6NLWEb6A3GqtWpkvFlUtJGS7o8xbETsL+mqMp8Knd/JBK7+mN5/ILdS/SJP9l1v6Wxt/wBqAquOap+niP8A/cj8lb62R/0lP8NiXcytp+EasjM+Lwm/enc2ED2eQfgFpeHaGnptXziaQ/Zp2OkHoHuytWXFXJK8Zjck77n4nVbiijDGAAW01/us2oySqpeTbpMMG7j4Ja/GQ0AiCUDMAXOezQONrloGm45qeSvyjtyQFV5wY7ZswII63GqmwTBXysIkdZ7DkeLEG4AINuV2uafdY79jqJxh+pjocYu6xVpTYoBfb3Vc7hN+pB9N7n9lS10EsBs4aIJvyWbsc+EzdMqGPtrqimNG2687osTPX91qMNxQGwJv3/dESeN1wXroVBJS3UsFQD3UxIQozttFVJhwPJN8ANGytXEIGpCDQyk2U0g86taZ6rp22IRMT1Wuy58oto5FO2RVbJlM2ZPZW4lm2RPEirBMnioTbhNge5yjLkM2dcZVLBtJSVEWhRmZNzoWNtMfF9HlEz+eRx9X2/JWFPhOGQ/c9zcqrpOGrj+JI4nuSrGlwOJnJdZ4Z+ZnBWox+IF4OJKUCwJIGmjSUJUcb0zNMjz/AJCmthjGlguno4nW8oS/w0fLYz1cvCQ5vFjni8dOfcgIVuOVpdpG1o6alWdOGtFgAnPeEVhxp9CvPla7Fp6uYjzv17BJI48zdQunAQ01WFbGCvhFUsjrlkufKU41aqpqxCyViuWOzO81dF6asIasq7tLRuVRPre6SecmN1r3ty3TelQnr32Z9xqZJXRxAOANs1yAtDS8IVDhd1QAegbf8yrDhajb4YIBB533Woi0VWZRT65NGnlOStvjwee4lwhWMF2Tsf2LbH81UjDatgLpNrbi2i9Ynfoqqts4sZ1eL+g1PyCENrXMUNNzTtSZhMIxzw3MOVt8wu7KCQOrb81b8dcPiUfXYCXtIHijct/GO3VDca4C2IiWEZWuNnMGzSdi3oD0+CXg7iB0LskgvG7Qg6ixVU4bfvgaYZN62T/JjxAnthWw4q4XyET03mgkOw18Inkfw9ChsNwXYuTb01YFjd0AYPQHMHELUTvs0noEQMNIAytJ9Bf8kraV2YB8bw2+t2n9lz9RK5I6mmShEtuGqIRxCR4u9+uu7W8gERPikUbnPsbm17He22iq8UxZrBa9lisZxUnXOss5tOolsYeo7keoUOMRS6seCRu37Q9WlBYrRGou3QDqf0HMrxKfFHB17m42IJBHoQtdwj9IQaBDVmw+xMST7Sk63/F8epsUtyqQZ4HB3At8Y4b8MXY4kjruVUUdaW6FayqxOOWzI5GOc8XaGuabj72h213VHX4AWgvB825/sg1zx0W4c3FTLHD8XtYc1pKatDm3XmkVTY91osGxLukZdOCas13iqCQoVlRdL4l0jkUqJDUbrgVHK5RZ0CxBYkT2y90CJEviKWGiwE6Xx0B4qUSIWSiwE/dd46AEqUyqWRxDPGS+L3QQkSZ1LBRDFV2KSWrVE+qTTVL1Ow8QsjotZKtOjrT1VN46kZKptJuZfsrUklaqdsqjlmQ2DPIw6eu7oKWtQUkqHc9WKKRRKUmGuqlC+dCGRMdImsCjYQZld4Ddx2Wag8zg0LfYLTBjQlySpD4YXLgtaSHKFK99lE6oCCqKtY0nJnRclFUh9TUoWKS8jXX2P/arqqrTcMq/MT0H5q/ZSMvq3IK4kn8Rpby0A031BVXRYWDyTcVqy+RoOw/Pr81eYXsFkySqVLwdTTxUobn5J8Oe+AG4zxHR7N7DmQOnULJYvWzU38Vx8Njj5Xta1zDzADiDy5br0KEKmxXhWGfRznhl82QWLA7m4AjRUPs00YKn48qnODY6oE8gWj9LIup4+xKIXdIxw7Nt+d1ang2mhmD2gktBGuW2otqANVX8QUcQjcSwexIWWc2pUa8eOLVtAjfpbmIyy08bx7f/ACs/jXFMUty2ANJ7AfNtvndMoMJgc1zyOegudFFPhjALtaEJP3GUUnwVVRKDqOeqFe66KqWWQjkiNDfBc8FYw2lq2yPHkcDG4/cDi05/YtF+117JU1AI7L5/Wl4NrZPOzxXBjQC2O+l3GxI5gC2w01T3wZ5Rt2aHFRaQlo059E/DqqxV7TwMdEDYaj581l8QAjkt12SpVwy+GS+Da0dXcBHslWRwer1A6rSRPVElTCTSvURekJuonlAZEhckL1DmTS9QIQXrhIh8yUvQCEteuzocOS50CBGdNzocyJpkUIZjx0onQAenh69eeCSLBsqmZMqxsilEiVjqJZeOmOkQQkUgehY2we96ge9JLIqyrrwNk6EaoNlmAQUlUSbNQcZdIVqcFwprbE7o2kI02+QnhvDSPM/dak1Fgq9rgAoJqhVNOT5LFJQVIOlrEDUVndBTVCDkmTbUhN7kTzT3RdAcrSTuVX0seY3OyLmksn7Fb2jJjeQf83K0uGbBZiLV91qMNC5M5bpt/J6HBHbjivgvIVTcS446GzIgM5Fy46ho5acyrqBpWN4to5hK6QxuyaeYWIsAN7aj3SSTSNOJwcqbM9UMqJHZzNJfe+cj5DRE/wCHVM0Zjc4OB0DiLOHrbQqekrYxqdVZMx6MC4WWSs6cYr2KnCfo6ksB9Yv1tH8v50RU/RxOAckzSOQcwt+YJVlFxSBsSFKOKTyd80jojxNvwed4pwJXMJPhh9vuO/R1ifZZaohcxxa9pa4btcC0j2Oq9xpeJ9Tm2T8ShpatuWeNpHI7OHdp3b7JVQXja8HgTykilLSHNJBGxC3mPfRs8XdSSZxqfDecrrfhfsfe3qsJWUkkTskrHMcOTgQfbqO4ViKJJo1fDXEtRrGQ1zAL3/lIv6aG57KzfSeKPFcdTpbp2CwNLWOjddp5WPQjotDhHEFz4eU2NzffKba+yEhF8F5h82WRoP3gPituNGrzrDQ+SpiIHlLwPXW9/kvSCy5AVE3ZcmK1uihcipShXJRkRkphKc8Jigx11wKRJdAlkgcuzKMJHOQCPLlGXJrimFygpjQ4p4lUF0q9pSZ8/TaCRME4Tjqg0hal2IsWRli2oHVK6qaOaqzF3TTT90uwf1h9bXE6NQsUJJ1RDKYIiNim0V5PYLw+MBaCmmVBEbI6CVBgpl06ZCTzKEzIaeZRcAabHSSodsuZ1ggZ6kuNmqxw+DKFHyPFKKLWDQKGR13WSPksFGJQNBvzPIJZOVVHt/8AWSChu3ZP0r/fwWOHUxc4dFqIXNYFnsMm0J2F7X5lPqqgnyg+voqIadQ4NctY8ivx7F1UcQ5B5W3t3A+ZQA4szmzoyAdAbgrO19WXeQHyt17n97IDfQX9Oq1R08ErZgnrMspbYMfjMIbK7IPKdQOl9x8boVjeoKvDg0mUZt7bfooBh7hu0rz2aUd729Wez0ryLFHf3XIDHAOhRDab1RsdOeYRccCps1b2V8FKj6eMjUbohkXZSxx9krYfUZLSykC1ypK6hinblmja9vIOAIHouY3spQlsF2ZOt+jOlebxySRdgQ9v+rX5qOi+jaKJxd9ZedLWyNHTnfstowJQo5sSkVOFYDFT6tBJF7Fxuddz0HsrSNlhfmfyUmXqoKmVVthQPI5DvKkkchyURxXFNSA2SEqEFKYUt0hQIJdI9ya4phKhBXlRriUzMgQyA0TwEhXBe0R8+Ytl1l1111AC2S2SJwChEjrJ7UganJWy2MGSNKmY5BuqWjmoH4geQSNlqRavmsgZZi7QIVhc86lWlJTpRnR1FS2VkDYJrRZAYhWZdBuUxSxauqJNh8lNCLWHPmg6CH7R3RtrJtyXBVLG5sOhqbCw2UdTW2GVp9T1QZkso3vG5Rxq3bBme2KURXPtr80Tg9c0PzAXcNugPVZ6vrC52Rm/M9P7rRcOUQAF1z/qOr49OH+f2O19H+n1/OyL+37m3wyYyCxGtrqw+oDoh8HYAfZW+ZcVHoJcPgq5cPb0Q7qMDkrp4Q72BBkTKowDouawIuRCuSWWI4JLqJ7krXIBJQbqRqian50AMSaSwVfI9S1El0O9qAyGXUZT7JCEQjSmFOIXWUCNSJxTHIEGuUZKkUZUIMKbdOKYfZQBkUqaCnXXsbPBuIoS2SApbptwNhxBTS5ycuCnDBbRGS/qmljjuVOEqFIm9g31ZSMgClTghSGTbHwsVhEUDGpXy2Fyq3wWpWTVlUGtuqumiLzmcmgmR1zsNlZwssENxHEliCc945qMvsoHuTRXuVSbfER75+QCr6uY27q2ZQkMzuFug5+pVdNFcrLn1dJxh+TpaP6am1PJ+AXDaXW5Wxw0gALP0rLK2p5bLjTPR4+DaYdUWHdWTKpZGnq9EUK0qplm2zSuqO6gfOqdlTfmn+KkbJtDJJboWR1+aTxE0pQjHFKHFSCK6mZCBuoSxjQo55VJM8BBuKABCU1yaXJERhQkypQlUINITSnlRuKgRpUbynkqFxRolnXTCVxKaShRDiU26QlICpQDGrhJ1SLl7A8MiQFOBXLlBWxQUq5ciBChOskXIMKVjwEpcBuVy5K2W0kQvrPuhQ+Z58xXLkrImw+BllOXrlyiAyO5OgVjR0dtXb8h0/uuXLFqssl9qOjo8Ma3FrPDmiPbX91QOYuXLFI62Po4Nsp4yuXKiRpiGRSIqOVcuVbLUydkylZOVy5IxieOQohj1y5ViskEoSOmXLlCA73JrgkXKEEsuyrlygwhCQlcuRIMc5RkpFygBhcmFcuRIMJUbnLlyhBpKTMuXKAP/9k=" alt="Dentistry" class="card-img">
                <div class="card-content">
                    <h3>Dentistry</h3>
                    <span class="specialty">Dental care specialists</span>
                    <button class="view-btn" onclick="toggleDoctors(this, 'dentistry')">View Dentists</button>
                    <div class="doctors-list" id="dentistry-doctors" style="display:none;">
                        <!-- Dr. Ahsan Khan -->
                        <div class="doctor">
                            <h4>Dr. Ahsan Khan</h4>
                            <div class="rating">
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star-half-alt"></i>
                                <span>(124 reviews)</span>
                            </div>
                            
                            <div class="doctor-info">
                                <p><i class="fas fa-hospital"></i> City Hospital, Islamabad</p>
                                <p><i class="fas fa-phone"></i> 0300-1234567</p>
                                <p><i class="fas fa-clock"></i> Mon-Fri: 9AM - 5PM</p>
                            </div>
                            <div class="action-buttons">
                                <button class="book-btn" onclick="openForm('Dr. Ahsan Khan')">Book Now</button>
                            </div>
                        </div>

                        <!-- Dr. Sana Iqbal -->
                        <div class="doctor">
                            <h4>Dr. Sana Iqbal</h4>
                            <div class="rating">
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star-half-alt"></i>
                                <span>(124 reviews)</span>
                            </div>
                           
                            <div class="doctor-info">
                                <p><i class="fas fa-hospital"></i> City Hospital, Islamabad</p>
                                <p><i class="fas fa-phone"></i> 0300-1234567</p>
                                <p><i class="fas fa-clock"></i> Mon-Fri: 9AM - 5PM</p>
                            </div>
                             <div class="action-buttons">
                                <button class="book-btn" onclick="openForm('Dr. Sana Iqbal')">Book Now</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cardiology Specialization -->
            <div class="card">
                <img src="data:image/jpeg;base64,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" alt="Cardiology" class="card-img">
                <div class="card-content">
                    <h3>Cardiology</h3>
                    <span class="specialty">Heart specialists</span>
                    <button class="view-btn" onclick="toggleDoctors(this, 'cardiology')">View Cardiologists</button>
                    <div class="doctors-list" id="cardiology-doctors" style="display:none;">
                        <!-- Dr. Sara Malik -->
                        <div class="doctor">
                            <h4>Dr. Sara Malik</h4>
                            <div class="rating">
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <span>(98 reviews)</span>
                            </div>
                           
                            <div class="doctor-info">
                                <p><i class="fas fa-hospital"></i> Heart Center, Lahore</p>
                                <p><i class="fas fa-phone"></i> 0300-7654321</p>
                                <p><i class="fas fa-clock"></i> Mon-Sat: 10AM - 7PM</p>
                                 <div class="action-buttons">
                                <button class="book-btn" onclick="openForm('Dr. Sara Malik')">Book Now</button>
                            </div>
                            </div>
                        </div>

                        <!-- Dr. Bilal Mehmood -->
                        <div class="doctor">
                            <h4>Dr. Bilal Mehmood</h4>
                            <div class="rating">
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="far fa-star"></i>
                                <span>(76 reviews)</span>
                            </div>
                            <div class="doctor-info">
                                <p><i class="fas fa-hospital"></i> Cardiac Hospital, Karachi</p>
                                <p><i class="fas fa-phone"></i> 0300-1122334</p>
                                <p><i class="fas fa-clock"></i> Tue-Sun: 8AM - 4PM</p>
                            </div>
                            
                            <div class="action-buttons">
                                <button class="book-btn" onclick="openForm('Dr. Bilal Mehmood')">Book Now</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Psychology Specialization -->
            <div class="card">
                <img src="data:image/jpeg;base64,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" alt="Psychology" class="card-img">
                <div class="card-content">
                    <h3>Psychology</h3>
                    <span class="specialty">Mental health specialists</span>
                    <button class="view-btn" onclick="toggleDoctors(this, 'psychology')">View Psychologists</button>
                    <div class="doctors-list" id="psychology-doctors" style="display:none;">
                        <!-- Dr. Fatima Ali -->
                        <div class="doctor">
                            <h4>Dr. Fatima Ali</h4>
                            <div class="rating">
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star-half-alt"></i>
                                <span>(124 reviews)</span>
                            <div class="doctor-info">
                                <p><i class="fas fa-hospital"></i> City Hospital, Islamabad</p>
                                <p><i class="fas fa-phone"></i> 0300-1234567</p>
                                <p><i class="fas fa-clock"></i> Mon-Fri: 9AM - 5PM</p>
                            </div>
                            </div>
                            <div class="action-buttons">
                                <button class="book-btn" onclick="openForm('Dr. Fatima Ali')">Book Now</button>
                            </div>
                        </div>

                        <!-- Dr. Imran Khan -->
                        <div class="doctor">
                            <h4>Dr. Imran Khan</h4>
                            <div class="rating">
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star"></i>
                                <i class="fas fa-star-half-alt"></i>
                                <span>(124 reviews)</span>
                            <div class="doctor-info">
                                <p><i class="fas fa-hospital"></i> City Hospital, Islamabad</p>
                                <p><i class="fas fa-phone"></i> 0300-1234567</p>
                                <p><i class="fas fa-clock"></i> Mon-Fri: 9AM - 5PM</p>
                            </div>
                            </div>
                            <div class="action-buttons">
                                <button class="book-btn" onclick="openForm('Dr. Imran Khan')">Book Now</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>


<div class="card">
    <img src="data:image/jpeg;base64,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" alt="Dermatology" class="card-img">
    <div class="card-content">
        <h3>Dermatology</h3>
        <span class="specialty">Skin, hair, and nail specialists</span>
        <button class="view-btn" onclick="toggleDoctors(this, 'dermatology')">View Dermatologists</button>
        <div class="doctors-list" id="dermatology-doctors" style="display:none;">
            <!-- Dr. Hira Qureshi -->
            <div class="doctor">
                <h4>Dr. Hira Qureshi</h4>
                <div class="rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(115 reviews)</span>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> SkinCare Clinic, Islamabad</p>
                    <p><i class="fas fa-phone"></i> 0301-9988776</p>
                    <p><i class="fas fa-clock"></i> Mon-Fri: 12PM - 6PM</p>
                </div>
                </div>
                <div class="action-buttons">
                    <button class="book-btn" onclick="openForm('Dr. Hira Qureshi')">Book Now</button>
                </div>
            </div>

            <!-- Dr. Ahmed Rauf -->
            <div class="doctor">
                <h4>Dr. Ahmed Rauf</h4>
                <div class="rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(102 reviews)</span>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> DermaPlus Hospital, Lahore</p>
                    <p><i class="fas fa-phone"></i> 0342-3344556</p>
                    <p><i class="fas fa-clock"></i> Mon-Sat: 10AM - 3PM</p>
                </div>
                </div>
                <div class="action-buttons">
                    <button class="book-btn" onclick="openForm('Dr. Ahmed Rauf')">Book Now</button>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="card">
    <img src="data:image/jpeg;base64,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" alt="Psychiatry" class="card-img">
    <div class="card-content">
        <h3>Psychiatry</h3>
        <span class="specialty">Mental illness and behavioral disorder specialists</span>
        <button class="view-btn" onclick="toggleDoctors(this, 'psychiatry')">View Psychiatrists</button>
        <div class="doctors-list" id="psychiatry-doctors" style="display:none;">
            <!-- Dr. Ayesha Malik -->
            <div class="doctor">
                <h4>Dr. Ayesha Malik</h4>
                <div class="rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(132 reviews)</span>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> MindCare Clinic, Lahore</p>
                    <p><i class="fas fa-phone"></i> 0302-4455667</p>
                    <p><i class="fas fa-clock"></i> Mon-Fri: 2PM - 7PM</p>
                </div>
                </div>
                <div class="action-buttons">
                    <button class="book-btn" onclick="openForm('Dr. Ayesha Malik')">Book Now</button>
                </div>
            </div>

            <!-- Dr. Bilal Haider -->
            <div class="doctor">
                <h4>Dr. Bilal Haider</h4>
                <div class="rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(118 reviews)</span>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> Serenity Mental Health Center, Islamabad</p>
                    <p><i class="fas fa-phone"></i> 0314-7788990</p>
                    <p><i class="fas fa-clock"></i> Tue-Sat: 11AM - 5PM</p>
                </div>
                </div>
                <div class="action-buttons">
                    <button class="book-btn" onclick="openForm('Dr. Bilal Haider')">Book Now</button>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="card">
    <img src="data:image/jpeg;base64,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" alt="Nutritionist" class="card-img">
    <div class="card-content">
        <h3>Nutrition</h3>
        <span class="specialty">Diet and nutrition specialists</span>
        <button class="view-btn" onclick="toggleDoctors(this, 'nutritionist')">View Nutritionists</button>
        <div class="doctors-list" id="nutritionist-doctors" style="display:none;">
            <!-- Dr. Sana Rehman -->
            <div class="doctor">
                <h4>Dr. Sana Rehman</h4>
                <div class="rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(98 reviews)</span>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> NutriHealth Clinic, Lahore</p>
                    <p><i class="fas fa-phone"></i> 0312-4567890</p>
                    <p><i class="fas fa-clock"></i> Mon-Fri: 10AM - 4PM</p>
                </div>
                </div>
                <div class="action-buttons">
                    <button class="book-btn" onclick="openForm('Dr. Sana Rehman')">Book Now</button>
                </div>
            </div>

            <!-- Dr. Kamran Shah -->
            <div class="doctor">
                <h4>Dr. Kamran Shah</h4>
                <div class="rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(86 reviews)</span>
                </div>
                <div class="action-buttons">
                    <button class="book-btn" onclick="openForm('Dr. Kamran Shah')">Book Now</button>
                </div>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> Wellness & Diet Center, Karachi</p>
                    <p><i class="fas fa-phone"></i> 0333-9876543</p>
                    <p><i class="fas fa-clock"></i> Tue-Sat: 1PM - 6PM</p>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="card">
    <img src="data:image/jpeg;base64,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" alt="General Surgeon" class="card-img">
    <div class="card-content">
        <h3>General Surgery</h3>
        <span class="specialty">Expert in surgical procedures</span>
        <button class="view-btn" onclick="toggleDoctors(this, 'surgeon')">View Surgeons</button>
        <div class="doctors-list" id="surgeon-doctors" style="display:none;">
            <!-- Dr. Zeeshan Ahmad -->
            <div class="doctor">
                <h4>Dr. Zeeshan Ahmad</h4>
                <div class="rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(110 reviews)</span>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> Medicare Hospital, Lahore</p>
                    <p><i class="fas fa-phone"></i> 0321-4567890</p>
                    <p><i class="fas fa-clock"></i> Mon-Fri: 9AM - 3PM</p>
                </div>
                </div>
                <div class="action-buttons">
                    <button class="book-btn" onclick="openForm('Dr. Zeeshan Ahmad')">Book Now</button>
                </div>
            </div>

            <!-- Dr. Hina Qureshi -->
            <div class="doctor">
                <h4>Dr. Hina Qureshi</h4>
                <div class="rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(95 reviews)</span>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> SurgiMed Hospital, Islamabad</p>
                    <p><i class="fas fa-phone"></i> 0303-3344556</p>
                    <p><i class="fas fa-clock"></i> Tue-Sat: 10AM - 4PM</p>
                </div>
                </div>
                <div class="action-buttons">
                    <button class="book-btn" onclick="openForm('Dr. Hina Qureshi')">Book Now</button>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="card">
    <img src="data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBxMTEhUTExMWFhUXGBUYGBgYFxcYGBgVFxUXFhUXFxgaHSggGBolHRUVITEhJSktLi4uFx8zODMtNygtLisBCgoKDg0OGhAQGy0lHyUtLS0tLS0tLS0tLS0tLS8tLS0tLS0tLS0tLS0tLS0tLS4tLS0tLi0tLTYtLSstLS0rN//AABEIAIABiwMBIgACEQEDEQH/xAAcAAACAgMBAQAAAAAAAAAAAAAFBgAEAQIDBwj/xAA8EAABAwIEAwYDBwMDBQEAAAABAAIRAwQFEiExQVFhBhMicYGRMrHRFEJSocHh8AdiciMzghUkU6LxFv/EABoBAAIDAQEAAAAAAAAAAAAAAAIEAAEDBQb/xAArEQACAgEEAgIABAcAAAAAAAAAAQIRAwQSITETQSJRBWGB8BQyQnGhweH/2gAMAwEAAhEDEQA/APDVFFFCEUUUUIRRRRQhFFFFCEUUUUIRRRRQhFFFFCEUUWYUIYUWYUhQhIW1NklahWaDVZaVss2tD06onQqtaI3Q11bSNlp3hU3UMKCQcF6uwuZ3goLZUy93IJntcPGXaUcbkXJxj2VKbQUQtbVp4LnXt2j4RCxa1yDlC3xyp0xfLBSVoNUcPHJXGWh4BZw62e+NE0WOEhsE6pxI5rYDt8PRO3w6EXNAAbQAuBqsMFp1RA2cWWw2iOZWoIGk6cdVreXsHLIjjBVG1u5JDW6fmVCBynUAEN3WlzXc0b6nkPksWjSd/wBp4q46nEGAT7KiwI+k4nUlxPCfmiNraxDmgQQSJMTH8K4VMYoWxdmHeVCSCwDSD1SVjOLFx8MgCY12B4QlcmoSfA7j0ra5HgvdUZnmGjkZjzXCXaCRB31+YSFT7WVqYAhpbEER8XmiOBdq2PrEVKYBedDwaI2Qxzxk6D8Tx+hqfSc8a7Dh+wXBuEySSBH84o7QcwtzAyOHJD77FWM5fRMKKF3mk+EZZZNaNAFRq2jJ1J9FwGNZ3QD7AlGKFg94nUfNECkvYGdbUwdGz5rWlhjSYDT7pjp4QBq/8lYfTptEAgdIkrNqzeM4x6QCZhbGj4Q301nquBZTa7UidfNW8RvwyQJnnoPQDmhdvZCoczsoPCJLv/qrbzwaeR1cnRyuKonTYCR1k+8oZc0C+ZO86cOknqm2jh4gbAf46+q557SkT9oeABwMa+nNW4WuTPzpP4o8px/BqjnAUmkjcaHToPZV6XYO+cARTInr+69dq3NKo/OxwayBl5x5K417I+J5Qfw8WBLPJs+a1FFEgbkUUUUIRRRRQhFFFFCEUUUUIRRRZhQhhRRZUIQBbALCyFZDMKZV2t7dz9h7kAe5RGztw0El2Xhm1Ou8CPmiouMW2UrXDajyA1h1MaiBPmUdssAY52Xv2SN8ozACCSZnYRBImFMQxKg6lSYwVHPbqXkwc07CSdJ10Q+riAMjIJiJEA+ZMS731VNo1hjaHFlrTbbNaBncS4ANbna0blz3azPkfmqlvh1CoMlYy4gFpZlGQayCfh3y6DadUt2V0aZcQQHO0D58TeoiSDw05rL7l1QCaYkffDdTpu7gTxlVvRuoMMWVtbNE53RMEFzZ/JsfNXsUuhSE0X5qempjf0PVLYugD4iS3i1pAGm2kRHFEbG6FJ4IeOoy6co8Q5FSOSugp4FMwcWL9DCv4AzPUjoqd5Z060upaVRMtBYGuMk+EN0MjiPVV8NrPpPBe1zDycC0+xWkZ8psWeGUbR7LgVrLZcQIVq5xGnTkAiev6Lzyn2kc1k+IjpMKg7EXVhvl5DiugppnNniakx6qY85xIkKlXxGWwBHPWAOpSn3ZpjMSZVV2JVNpEcS79OatzoFQsaK1w06B8uPTj0Vu3OSBJ19CfogOG4o1rRlZPN2m/qquJ40+SG6meCvckTa3wP7MSptAlw0HA6A8PModU7Qh+ZsGRPHRI9pc1nzOg9z+yY6Fm/uATTIPONfWdVjlk3F0b4IR8i3A2u8ySh1d0zqrb6kSh92/ouO2d/aqB1w9Ve/1G673T0OcVaYtkiezdja9S4tQczfD4QOgV6r2fZmzPPudj5JX/prh1Zzc7X5W8W8xtunx4aPiM8/oF2MTbgrOLlVSdFOg2k0w0HTp8kTdiQaNBtzK60LJpGYNgLo2ypkcPkjbRmLl9iNSqYaNJ8p6BEaTe7py8kOjQaAx1PFdrgCl/s0i9x4/uUH/AOnXNUl9chg4Sfy0UDUvooue2s+DTHqZ/JHbSwNP4ImNgP1S/atdTrOIDqs6NyNytB6ymixtqhGaq/X7rRsPPmr6Kk77Yr4veVjUFIUnCTq4SFQ7TYLIZ/pB7iQTUe45R0TRi7rekc1a4I0nKPiPRoGqVT/U2m5/d0LdumjXVDJJ8hsglJBRT9BSxtgWgsoFxEeKHNYT/bm3HVW3V64Md/Qb0kadFS+31a9I1a720wOBnXo1vE/JaUe0NJgDRRY6PvHc8SrslM8IUUUXLGyKKKKEIooooQiizCyGqEMQsgLdrF1bRKJRKbOACyGq9Tt+isstEagDuBWRZ7ko3TsQrNPDZRrEC5i53BXRlumP/pJnRQYYdfCTAJgdBKvxUTffBVw8Cm3XScpmSNZ5wRCqYjUDjLT4dgPUkk8994C6X9XdkARI6yDuR7jX6IaTpulpSH4xpI3aRsTA9/yWqxOn7KSszRGwXSmSNiR5FatCaf6f4JTurxlKoRlyvcQZ8WUfDIIjffogbGIQvkXAOf1TBjOHNptotpseXupMc8jMQ5zphoEGCAAvQcA7N29A1HfZu9c/whlZzSGNmdNJPnyCYcGrVm53hlIBxlsN0aBpA120V0l/Mw7f9Ks80suzQo1KoqVHkUrYV8zWEMD4B7txPnHXVVhhdWtDTTIcJJMgMGhOWT4Wnbjr0XsVriGa0dTrNDC7NLobB8Rg8VQx59u+jTow1gkZoMN55iOMnfzW0YquzGWV9SieW0MOcDqdtIOh8kXsaDGfc8X5eqtYtWcymG7tnNIBniAdtG7xr5oZQdVfo0iRuSIjp1Kdwvg52pir5LF7ULgQQGngB8kIZhr3kZxp/OPBMthg2viOd/ICU1WvZ9rYLnHaSITFX2Ibq6FTD8Ge5gDGgAHb9RxRm17EAu8QLpjaN03W9KnTbvA/NSr2hp0xAGqjf0ilbOGHdlKVPXI2RzXa8s6YBHMbShtz2ie74QgNevXquILjH9o/UoVu9lqIr9obTu6rteJhLl2/mvSqfZ5r/wDcOnMuk+kJExfC3uruZQYXNmATo3znkudnwNO0dnBqlKNMXqplU3OHHbj5J3d2HIbLq0nk2AB5k6lEMJ7CWw8dZ5dGzZ3PpCqGnl2wcmddIL9jcYtWUg2lmAgSDz4wmum9tRpc0Fh4EiUk4hif2aGWdk2oSYzOmBykAj3VsY3ceFrzT28bm6U2nk3iV0YyS4OZPG+xrFevmaGuB01019AtMQuO7HjLGhxGr3a+jQEBqdoBo01X1HHT/T0AHKY1VbFDaUYfWLy8wcmYl2v4jsN0W5A+OXtBd+IUS9rDdFp4wSAR/aQUSo4jQdDWF9UA+Iw4/wDsRCUjilFzR3dIa9JPTUrejjtRhDHFob+EgkD2V3YO1jJVNR7pbV7qlOxY0GP8iRqs4jirQwNpuOYaZzqPbiUu33aim0Q4B7Rt4GiOoKUu0uPCu5r21i0N+FkNyjno2JJ5qm6IkOtziLDU1p1XO2LhTpDMPV6y+vS7simWUzxOSXAn8RYDPoV5jd4xBzZZMDjp9UMve0lV4yzlb0QSyxXYccbfQ74zhb2kOaW15E53EtAM7ZSZPqI1VOhf3rWgd7TEcMrNP/VLeDYm6Ie95b/l9UcGJM4NHqSqUk+QqkuDz5RRRc8YIootgFCGIWYWwatxTRJFWaNau9KjzXSlQVynbnktIxBcjlToqwy2Ku0LRXqVotlAzcgfStleo0Fdo2Q3hXadpPBaKADkUaVuOSI0bYHgrlra8I90Wo2JOwWqiZuQHZa8gljtTcBrixrnteAAQHQ3edhuYI1ML0gWMa6LyjtdcMfcVMrYhxa7fUtOWduk+vql9U6gM6NbpsDPdruVzKyQoAuadOiNatw1YAXRgQtm0YhDAKDX3FIPALM4zBzsoLRq4F3DSV6McBt7Ui4s6/fd6XhrREsYTtIM8Yk7wvOcKq021WGqzvGT4mSWyDpuNo39F6Hh9iwODaTXUqbTIDnS4zqZOiynPbyOQxbqSCGE1D31NrmEgA667nmiuJF9IDLOQTLRxVzCLZo1RGpbS0gmZM68Ek8jfIy9sHtE67eajGVGFwj7p4RzCEYpVOTM4Q8nhz4QmyrZZDULTo4at5RxCVL0kFtU+KnJHrsihlfRc8aastt7UONPu3NOV7MrgIkOiJCMdncAZkDnB+pnxQGxwA4k8ykOo8NMmRrMDlwThheLOfTc4NEU98x1AiRoV19Hne7azia/TJxcl2OFJlOk3whrfIAlDLvEXuJDZA58SufZ8VKzM7zqdQOh2RNmHHd0ALq2mcVwcRXvMTqk5GglWsOwtz/iGqZbfD6UkwI4n6K1b0mt+GSTsgckjRQk1wgXQwnXnCsuw6CHOLWxsDt7BEnFtEaDxH1M/qlvEyXky4k+X7z+SyeZJ8jUNHKStI1urpjneKHcANm+3FUb6HQ0HbgNIVT7E5hzFzWbwHGfUAan2V6xtacZn53N/GfA0/4zLne0dVk5ScuBqOOEYW1QJvaDnPaBOVu4H3nLrTw+q4lztOhMR6I2ylWfULWU3MpjUObHiHRxg/kFdde0LeG1Hta8iQwuE+bidvUolG3yBOajHhoVjgNQydh5Ek+QCo3nZY1Rlq3Drdo4PaAHH1IJRvH3OrgxeGmOVIA6e490rXHdiGy+s4fedAJPD4RqtvH6EXmd2XBaC1olloe8rbd88bdKbRow8jBK4UMCMNdcEaals6l25Lj5nzW9piDaZyuytd+GTPrK0u3Mdq5zgPUq/HH0D5ZPsH3mIMzllFuUjSchaz0cRHquLmxrUrMnzEe/FUcctnnWnmcI0MGEr/Yrh7g0U6hJOkAwglJr0RU/YbvrumXFgfPXWPdL13Rc0pob2eFMDvnNDuUyQu9rZMecrfF6fqptclyU3TE9jXlW6WDOcBGk8091Oy/dgOyNIP8AePkruH2hpeKp3FNoGgjMVaw/YLyfQs4f2baxsuDnHgB+q7HDgND3Y6Etn5o1i+IsqeEViG/4QPZADgdI694/Xk390e1Lorc2Iaii2AXOGiALq1iw0LuwI4oFswxistapTpztuu4pHitEgGzpQpgolb0kLYSFftnOOwK0iAwvb27UQt7cKvhtk47pmsbKN0xFGUmVKNnOwV5mH7bK/TpAbQtHlGgLObKTW7qzTrctlmjr92VYFk52+gRFGtN88CV492xpNZdVqbdAHyAOGZocZPmV7jb0Y4CF5P8A1UwltO4FVh/3pc4cnCAYPIzMeaT1iuF/Q7oZfNr7EYrLVkt1WWhcw6yXJOC6NWgC6gIWzaERvwrs7av7qq28Y5jQHVqZGSs1wEwxp+MEwJCKW1+9x8Ikl0mTsEM7LnCsjDcuuKddrjLmAOYRPhOxIA4rR1bI9zaT5aXeB2vibOh1S2XlDeJ0z1CnVHdZQM2aB78V3x7MxjGsPjGUgTuARP5IBguI+ITtA/JML7hjnCpoS0EeUrn3XA1OD3JrozWpMzGpHiLYjh7JMxLD8wLdmgkxwTPQvJc6m7cag8CD1QfGKBayoWkmSHT+GOARJ8hwjSaYrnDm1HwPDlIzGeATLhVIVazgXthuUGRo5nmOPBDMUpWzcub4ntDi5pI9NFrb5KQFajtsGgyJ5uO4T2CdcmMsO90qPVMMuQ1ujAWjlEg8iOPstKr6dQy+q0Afc2MdZShZ4vUeGvAMwfCA0GYIn4cxBOm+x6FWCczA5geHffpufUkcsoa6PQwR1XQWoEZ/h0d1v/AwVLxmbKKZLREEbEcdlpUxkUg4tDo57AeU/RLDMOvapzCkIHwuqASOoNSZPUrgbKs5xFzLiIAfJezfixpiRz91TzM2jpMadcP9bL1z2lzbceRknoXH9Atbe2uaxGXLTYeJa0k/4zJV3CMBYXiCTzMEuP0CP3tRlv4/iOjWtGsabABSCc+WVnzQx/GC5A7bGux0UGNqVBoalTw0Wc5jVx8oRY3AYxo8L6sDMRqM8eLKDwnZVsSq1HCGlwzCdd44ANHyXGxZTp6kufU/CCNOrzs1NwikcfNNzdyNq9ncunxNYDvr4o6nh5BCKmB0g6agdUP84lY7Qdt6dLwyC7k0yB5lLZ7bufswH0O3ot40JZG36GKtVYxhik1o5OOqAi4qAlzadPLwhrSZQHGMbLzAtierS+Vm1w8mH0+/ZO4IJE+q1TF2jtinaTuY/wCxp1Ha+M0tPcKvR7XOq6Pt2AdG6JzsLQupgDM93EEQPZd34T4fFbBh/Eqp32RyX0A8Mx8xAptgcIhXX4vm0ho8wiFrhkDwho6lb1ez2YzmCIDgX34dRrk94B/kCR+SoVrGjRINPM6DJbzjrumitaU6RAJCsUra0OrgHf8AJRoidHm+KVLuu+WNhvIaR0V20wK4fGYe5ML0Wha2o1awD1n5rNW74MH5ShUQtwm0ey//AJHQOn1KvC1oN0lunmiN4zMDm3S1WsTmMEQiqirPIlkLoyiSrP2WACVy1Fjt+ivTYSrttbSdVwzRste9Km6jTYHGUWhWKbGnSUAZXPNWqFc7o1lL8KY0WmHN4ao3aYfHAJXwrGCNCmqze54kGU5inGS4E8uOUQjTphi6tux+y0o4e46kopbYY0GSFuLcGLZ+bYFWWYeNyrtCiANgF0c9rRJOg4qrIcmMyDQD1XNtzqWwTGs/sqmJYwymS153aHMgHUmdzwQuli5cWBr/ABAAfFAkRqSAJk/JLZtQo9D2m0jydjfRs3v3a4NAknbhpufkvNv6w4bUpigTOQl555TDYBcNNZ/JObcarVNHuPUcJG+yPWjG1abmPAc17S0tdqCCIII5JKeoc7idSGgWNKf0fMcKQmLtJ2Zq2T2062XM5uYFhJBExxAPBAiNOqWGdhzAXVi1hbtCFhxR0okSJ1EiRtpxTQ6iKrDWp0nUqLNGud8J1AIzcTKVgiNtXe+n3JqPyAlzWZjkzHjlWclYzBXwMuGXDmVQHkw4DKIjcSD5FHLC9y13CRkcw5tdnTp+qUW42B9ma9hf3UioDoXNnwtDtwAOaI1LOO/NNxc9gY54HiApu4AjUlpISmTHbGIzpUxgtriamQGMmZzCdnTwJ6Ltd4xkt/Gyah0c0QfMpQ76o6mwFzmn7kCMx1HtoVQtLu4BcMskkSd+mkmNVUcLYTyW0q7ClnR78uNN5YGGYIkyTtHJN2D2JeyHmkHSdwWA67ExE8dUK7PYTVeZNF5qbgasJPERxECZOibrPAqz6LTVblpufEtcHOaJI1EREiPVNwi/oGThiXykk/36OT7q0o03Cq5weZa1oaHTpoXEau9SAURse1NDwUxSjSdmtboOOui6Yl2GptAcA6owQSJBe3q0kQR0jRVqvZiiYd3jnNc6C0gDSZOo8vzW/wA10hZT02RNuTf+mWal2CZZLQTqNMpPlOnoqovKYlz5aM2UB0SeRGWZB4K/b9n7ceFtPTq4n5nZaVMLovMAEtbrq7wjqjq+wVkxrq/3+pZtcWAGVlMydNCOm/L2WtaqKQNas1oIOgkkiefCfJEaLqdJucwBzPFCr+8c4eBoIP3nD5Dgm4RZzcs438UB8ax6qGl2lKkRqQP9Rw5N/COpXnuP9pa9Ud3btIB+63SB1Kd8QuKRflqDOBvJgTyQS+uqZeDTYGN2ho1W8cbYhmzJcIqdneyVJtsK92/NVeSRSnRo/u5lcMWvK4OW3pMDdpA4LN3Wq5stNhjmZCMOumtpjvS0H1A/daxikhSUmzv2awCrVAfWqifwtARe5sqgMB4DR/N0tf8A6nKzLRbPX4W+w3Qy/wC0Vcjck+wARXQDGt2Im2JObfl+6r1u0VSr8JMdRKTLmu6u3xujmeA+qI4PavgQ8n0gAK7tgtDVYuqfeI9lwvcTe05Rr7rU3bmt39YOqCOuCXyZ9DqiKLF3euJl2nmF3s39PdVbkSJiI3LlTp3rGn4pJ48gqINNCtw/Ra3V7l4x+qXnY+Nmkk7LgbpzypZKDrr3NxhcjcD8X5IDc3eXV0noFtTrkicpE/3QpZdCva4cEMxWtByhN9QANPkkS+dL3eaSzfFUhrDy7OErLVhZCUGUdAVu1y5AraVRoi1RrQnvsViILsrl59SbJhMvZhrm1R5esLbDNqSJlx74M9cDgNlh10VTpEkBWG287rqnFaadHRt16qpcYhLXkjRhh7d/CSBJHKNVfZbhJHbetRyA08zXuf4zDmyAIIM8NBAWWae2NmuGG6VAq7xF5nXwgkDjpOgE7Lvh90Igj15JXFzJG5j2RC2uRPILiTk2z0ODgfMMudk74VUAh0ryywxEA6bJqwvEthOiyUqZ0klONDjj+C0r6i6nVaNiWP8AvMfGjmnh5cV88YrhNShUqUqg8VN2UwZGuoI6EQV75b3sCJ080kdtbEVqznNgFwg6TmIa0tHn4XD/AJLSc01+Zli07TafR5ZlXV1AgAnjsrNe1I14Ekeu8ey6UaZewt4t1A4kcf50QNmkYFMUidgsgEdCETsqfg/5H8gFVZEkOGhP/IeX0Q2aeOkmd6NTvGOkw4AT/cJ29Fmwu3UHNdbvd3hGsDw67sykeLgidlg0MLs2jzlBIy7SCNdCQeR4Jr7J/wBO3XBD+9axrGiCDncXlxh2UEZQBzO6pLc6QeVrHDfPgS6d3VqNzmSadQOOmjQ4kkRwEyn3+nDbOs0szBtzIIa8w1zREBh3BkSeOvJEO1v9M7hzaZtaxqOH+4HuLZIHhcwknroTxSJYdjL4lzxRdDHuaTLQQ5p10JmOsQiUXB8oxWbHnjUJ1+fR7VXvW0e7YGCm5vxBxJzM3OQ653E7SeKs2WJ0mW7e9cG5s5g6GC46QvIXY9cU6TaVSj3jGHwvqZi2Z0Ic78l3wqvVvHgV6bC0EGcxZIBktHMRK0jltmUvw5bfk+u6/wCnptrjjW/6bczmAQ1+pJ305rldMf8AGyG67PmDz0Gsodd9qrG3qtYwwWgNytaconbbRVLrtlTe7LTaS7eTp7Dda2vbMoYZN3CNX3Y0DXoCNRxP0CrXVHi4wxvAD+SULs8VqDxVCxg5bkj9FwxHtRnIEZWjifmmsWJvkQ1OaON7bLGJ4izL4mEAfCDx9OKG/wCvVHFjeB2MdFUr9o6YOgzHy1Qy87S1J4Ack5GCRysmdyCj8Eb946cZIVmhaUW6Bo0Stddoahb4ZB9IVfCrx0y4k8+Q+q0MLY2YgWuBDAAeYhKF1hTnu8RJV1+MtDviCzc4owjQyeQ2VOi02UbTDshM6xqQPhHLXiq9zc0xI368EIxvHCNAfQbJXuMSJMySeXBZyyKIag2NVz+LOAOQH7ItaY6GNjMNuMLz2jiNQ6A6/JWWlw1JnzQrLfRHj+x2q9o5EB42nb6oMe0RBJ004pYff66+Wi5PrgiAdFTzFrGM132imdSZ4fsg5xRxdynRCnVdZXW1olxlZ+VydIPxpDfYSRBgAcBAPqidu8bDTqgFoBTGm5/hcVbtboE75uvCUxFmLRfuKJ+6ZPM8Fy+zu4n8kTs3siYErv8AZCdUdA2DL6nDT5JAvGw93mvRL6mSk/GcIe2Xx/8AErqItq0MYGvYEWVhRJjVmwK2BWi3piVQaLFBwRfDr0scCOEIGzQo3aUswkDnw4clEMwfB6LgWOtqAA7+2qLXGMgbeLl0815/hjHfdCcbF3hBe0bctfknMc5NUY5IY+2iy/FnOGgI5TxQDtBQrXLMhYfD4g6QBPDgSfLTzRv7cz7rY9PlO641bvvYYQeMQ4t30M5SJ8lo4blTYu8ih1E8lLo0XejW4SjvaTs3Ug3FGn/obS0jdpLXEN3AkJXErmZMe10N4slhujdQdCj+F4oRA9knUqkbFX7WsQJ/mqXlE6OLLR6TaYrOkqljbw5pDpjNmkHX4eCW7OuSY4Dcoi/4C585A0TruZIIHJA3R0sPzTb6BD6fhdEO1bpx0IAAPPxLrZ04q7ACQJO7ncAP5wW2G3bBOcAZjoZPhE8o9Z6JloYMfsz7uqMsQylmiXPc4N8DdgBPxGdtFOWHcI1z7BdfDJaTTywdXQRLc0S6OUHbzVjCew1Wse9dUpspZp+Pxlm4c2BpOg12XXDe/FRhZbmtSzEVGFoIIEO8wRz6r2HC76jdQe7DQ0CWOaA4GNiOQR4obuxXXZ3j4iuF2/or4Fh9tHcOp0nvazUBuZgYToGkiPPiTquNx2Q7p7qttVewxpTkZY5NO49ZTEXUqYgBregAHyVatiRDXHeAUy4quTiQy5d+6F8+n0yvaPuMjXscHgjVr9HA7ESN1TqvuC5wbbxm1MuETt9FbwC8/wC3aTpOY+7iVwxLGm0hpq47c1OK7NIqSyOKiv3/AGoCVsLqhjPtBpUmtLjlB7w66CJAE+iWMS7MTUL7Z7qZcC1zn+Iwdy1v3UfuMTYDnqHNUPM6NHIDgqdTtZbjeJVLA5dId/i1i/mfP5dArCuw9Kn4n1nz5AT11mFSxqvTovihTBd+M6lZxbtOwnQzy10HoEFOLN33Kew6aMeX2crVfiM5rZF8FyjeVXGXlcMRxFV62JCOSUMZxMkkBMymoo5STkxgGICdJceitC9PRo5nUlJttcPAkk/JZpXReY1QLIFsGS9xtrRrBKH0+0RcYzQOiEYhS5oeICCWSSYUYJob33OcaEOQipWfMNMeaDi5cDoV2ZfunXXzQ+VMvxtBivhrnN3niSgNS2dJ0KN2uKF4yxHlsrR7s7mVbiplKTiLbGlq3r3RIiICN3NqHayGjy1Q6pZtG0u9ELg0qQSkmC1EXoYG52u3n9FepYJl3Hr9ECwyYTyIB2doXnYwmzD8KHCY8lZtcOIiG6ddEZohjdJ24D6pnHiUTGeSwFVw7UyfRb2uH66bj2TBTtGnWFuKDAdFrtM9xXsaIaNdT7K2J4HRYcRyPoFVfWM8QiBP/9k=" alt="Allergy and Immunology" class="card-img">
    <div class="card-content">
        <h3>Allergy & Immunology</h3>
        <span class="specialty">Allergy and immune system specialists</span>
        <button class="view-btn" onclick="toggleDoctors(this, 'allergy')">View Specialists</button>
        <div class="doctors-list" id="allergy-doctors" style="display:none;">
            <!-- Dr. Ayesha Noor -->
            <div class="doctor">
                <h4>Dr. Ayesha Noor</h4>
                <div class="rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(102 reviews)</span>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> Allergy Care Center, Lahore</p>
                    <p><i class="fas fa-phone"></i> 0345-6789012</p>
                    <p><i class="fas fa-clock"></i> Mon-Fri: 11AM - 5PM</p>
                </div>
                </div>
                <div class="action-buttons">
                    <button class="book-btn" onclick="openForm('Dr. Ayesha Noor')">Book Now</button>
                </div>
            </div>

            <!-- Dr. Usman Tariq -->
            <div class="doctor">
                <h4>Dr. Usman Tariq</h4>
                <div class="rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(88 reviews)</span>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> ImmuneMed Clinic, Islamabad</p>
                    <p><i class="fas fa-phone"></i> 0311-2233445</p>
                    <p><i class="fas fa-clock"></i> Tue-Sat: 12PM - 6PM</p>
                </div>
                </div>
                <div class="action-buttons">
                    <button class="book-btn" onclick="openForm('Dr. Usman Tariq')">Book Now</button>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="card">
    <img src="data:image/jpeg;base64,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" alt="Neurology" class="card-img">
    <div class="card-content">
        <h3>Neurology</h3>
        <span class="specialty">Brain and nervous system specialists</span>
        <button class="view-btn" onclick="toggleDoctors(this, 'neurology')">View Neurologists</button>
        <div class="doctors-list" id="neurology-doctors" style="display:none;">
            <!-- Dr. Sara Khan -->
            <div class="doctor">
                <h4>Dr. Sara Khan</h4>
                <div class="rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(120 reviews)</span>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> NeuroCare Hospital, Karachi</p>
                    <p><i class="fas fa-phone"></i> 0322-5556677</p>
                    <p><i class="fas fa-clock"></i> Mon-Fri: 9AM - 5PM</p>
                </div>
                </div>
                <div class="action-buttons">
                    <button class="book-btn" onclick="openForm('Dr. Sara Khan')">Book Now</button>
                </div>
            </div>

            <!-- Dr. Ahmed Raza -->
            <div class="doctor">
                <h4>Dr. Ahmed Raza</h4>
                <div class="rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(98 reviews)</span>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> Brain & Spine Clinic, Islamabad</p>
                    <p><i class="fas fa-phone"></i> 0300-9988776</p>
                    <p><i class="fas fa-clock"></i> Tue-Sat: 10AM - 4PM</p>
                </div>
                </div>
                <div class="action-buttons">
                    <button class="book-btn" onclick="openForm('Dr. Ahmed Raza')">Book Now</button>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="card">
    <img src="data:image/jpeg;base64,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" alt="Family Medicine" class="card-img">
    <div class="card-content">
        <h3>Family Medicine</h3>
        <span class="specialty">Comprehensive primary care for all ages</span>
        <button class="view-btn" onclick="toggleDoctors(this, 'family')">View Family Physicians</button>
        <div class="doctors-list" id="family-doctors" style="display:none;">
            <!-- Dr. Nadia Hussain -->
            <div class="doctor">
                <h4>Dr. Nadia Hussain</h4>
                <div class="rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(130 reviews)</span>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> Family Health Center, Karachi</p>
                    <p><i class="fas fa-phone"></i> 0312-4567890</p>
                    <p><i class="fas fa-clock"></i> Mon-Fri: 8AM - 4PM</p>
                </div>
                </div>
                <div class="action-buttons">
                    <button class="book-btn" onclick="openForm('Dr. Nadia Hussain')">Book Now</button>
                </div>
            </div>

            <!-- Dr. Kamran Malik -->
            <div class="doctor">
                <h4>Dr. Kamran Malik</h4>
                <div class="rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(115 reviews)</span>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> Community Clinic, Lahore</p>
                    <p><i class="fas fa-phone"></i> 0300-1122334</p>
                    <p><i class="fas fa-clock"></i> Tue-Sat: 9AM - 3PM</p>
                </div>
                </div>
                <div class="action-buttons">
                    <button class="book-btn" onclick="openForm('Dr. Kamran Malik')">Book Now</button>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="card">
    <img src="data:image/jpeg;base64,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" alt="Obstetrics and Gynaecology" class="card-img">
    <div class="card-content">
        <h3>Obstetrics & Gynaecology</h3>
        <span class="specialty">Women’s health and pregnancy specialists</span>
        <button class="view-btn" onclick="toggleDoctors(this, 'obgyn')">View Specialists</button>
        <div class="doctors-list" id="obgyn-doctors" style="display:none;">
            <!-- Dr. Hina Mirza -->
            <div class="doctor">
                <h4>Dr. Hina Mirza</h4>
                <div class="rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(140 reviews)</span>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> Women’s Care Hospital, Karachi</p>
                    <p><i class="fas fa-phone"></i> 0321-9876543</p>
                    <p><i class="fas fa-clock"></i> Mon-Fri: 9AM - 5PM</p>
                </div>
                </div>
                <div class="action-buttons">
                    <button class="book-btn" onclick="openForm('Dr. Hina Mirza')">Book Now</button>
                </div>
            </div>

            <!-- Dr. Aliya Shah -->
            <div class="doctor">
                <h4>Dr. Aliya Shah</h4>
                <div class="rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(118 reviews)</span>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> Mother & Child Clinic, Lahore</p>
                    <p><i class="fas fa-phone"></i> 0300-7654321</p>
                    <p><i class="fas fa-clock"></i> Tue-Sat: 10AM - 4PM</p>
                </div>
                </div>
                <div class="action-buttons">
                    <button class="book-btn" onclick="openForm('Dr. Aliya Shah')">Book Now</button>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="card">
    <img src="data:image/jpeg;base64,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" alt="Anesthesiology" class="card-img">
    <div class="card-content">
        <h3>Anesthesiology</h3>
        <span class="specialty">Pain management and anesthesia specialists</span>
        <button class="view-btn" onclick="toggleDoctors(this, 'anesthesiology')">View Anesthesiologists</button>
        <div class="doctors-list" id="anesthesiology-doctors" style="display:none;">
            <!-- Dr. Asim Raza -->
            <div class="doctor">
                <h4>Dr. Asim Raza</h4>
                <div class="rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(110 reviews)</span>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> City Hospital, Islamabad</p>
                    <p><i class="fas fa-phone"></i> 0301-2233445</p>
                    <p><i class="fas fa-clock"></i> Mon-Fri: 8AM - 4PM</p>
                </div>
                </div>
                <div class="action-buttons">
                    <button class="book-btn" onclick="openForm('Dr. Asim Raza')">Book Now</button>
                </div>
            </div>

            <!-- Dr. Samina Qureshi -->
            <div class="doctor">
                <h4>Dr. Samina Qureshi</h4>
                <div class="rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(95 reviews)</span>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> Mercy Hospital, Lahore</p>
                    <p><i class="fas fa-phone"></i> 0300-5566778</p>
                    <p><i class="fas fa-clock"></i> Tue-Sat: 9AM - 3PM</p>
                </div>
                </div>
                <div class="action-buttons">
                    <button class="book-btn" onclick="openForm('Dr. Samina Qureshi')">Book Now</button>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="card">
    <img src="data:image/jpeg;base64,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" alt="Ophthalmology" class="card-img">
    <div class="card-content">
        <h3>Ophthalmology</h3>
        <span class="specialty">Eye care and vision specialists</span>
        <button class="view-btn" onclick="toggleDoctors(this, 'ophthalmology')">View Ophthalmologists</button>
        <div class="doctors-list" id="ophthalmology-doctors" style="display:none;">
            <!-- Dr. Ayesha Siddiqui -->
            <div class="doctor">
                <h4>Dr. Ayesha Siddiqui</h4>
                <div class="rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(132 reviews)</span>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> Vision Care Clinic, Karachi</p>
                    <p><i class="fas fa-phone"></i> 0315-9876543</p>
                    <p><i class="fas fa-clock"></i> Mon-Fri: 9AM - 5PM</p>
                </div>
                </div>
                <div class="action-buttons">
                    <button class="book-btn" onclick="openForm('Dr. Ayesha Siddiqui')">Book Now</button>
                </div>
            </div>

            <!-- Dr. Kamal Ahmed -->
            <div class="doctor">
                <h4>Dr. Kamal Ahmed</h4>
                <div class="rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(110 reviews)</span>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> Eye Specialist Center, Lahore</p>
                    <p><i class="fas fa-phone"></i> 0300-1237890</p>
                    <p><i class="fas fa-clock"></i> Tue-Sat: 10AM - 4PM</p>
                </div>
                </div>
                <div class="action-buttons">
                    <button class="book-btn" onclick="openForm('Dr. Kamal Ahmed')">Book Now</button>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="card">
    <img src="data:image/jpeg;base64,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" alt="Colorectal Surgery" class="card-img">
    <div class="card-content">
        <h3>Colorectal Surgery</h3>
        <span class="specialty">Experts in colon, rectum, and anal surgeries</span>
        <button class="view-btn" onclick="toggleDoctors(this, 'colorectal')">View Surgeons</button>
        <div class="doctors-list" id="colorectal-doctors" style="display:none;">
            <!-- Dr. Saeed Anwar -->
            <div class="doctor">
                <h4>Dr. Saeed Anwar</h4>
                <div class="rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(87 reviews)</span>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> Surgical Hospital, Lahore</p>
                    <p><i class="fas fa-phone"></i> 0302-4455667</p>
                    <p><i class="fas fa-clock"></i> Mon-Fri: 10AM - 6PM</p>
                </div>
                </div>
                <div class="action-buttons">
                    <button class="book-btn" onclick="openForm('Dr. Saeed Anwar')">Book Now</button>
                </div>
            </div>

            <!-- Dr. Hina Tariq -->
            <div class="doctor">
                <h4>Dr. Hina Tariq</h4>
                <div class="rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(74 reviews)</span>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> City Care Clinic, Islamabad</p>
                    <p><i class="fas fa-phone"></i> 0311-9988776</p>
                    <p><i class="fas fa-clock"></i> Tue-Sat: 9AM - 4PM</p>
                </div>
                </div>
                <div class="action-buttons">
                    <button class="book-btn" onclick="openForm('Dr. Hina Tariq')">Book Now</button>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="card">
    <img src="data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBxMTEhUTExMWFhUXGBoaGBcYGBcaGBgdFxoaGBcXGhcaHSggGBolHRgXITEiJSorLi4uHR8zODMsNygtLisBCgoKDg0OGhAQGi0lHyUtLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLf/AABEIALYBFAMBIgACEQEDEQH/xAAcAAABBQEBAQAAAAAAAAAAAAAEAAIDBQYBBwj/xABCEAABAgMFBgQFAgQEBAcAAAABAhEAAyEEMUFRYQUScYGR8CKhscEGEzLR4ULxFFJicgcjkqJDU7LCFRYXJVSCo//EABkBAAMBAQEAAAAAAAAAAAAAAAABAgMEBf/EACIRAAICAwEAAgIDAAAAAAAAAAABAhEDEiExBGETQSIyUf/aAAwDAQACEQMRAD8A9QBjoMRBUd3o0OckBhPEe/DSuEMn3o7vQPvwvmQBYRvQt6BvmxDPtiE/UoDiR6XwDDiuOfMiinfEEoXEq4D3LQPK23MmndkyiTjeWe4k0CRxMKx0zSGbDVTminOz7cr+VI/vSP8Apcw0fCU1dVzUPwUr13XgseofO2tLT+sE5CsAr2ypRaWkk3XFR6Ji0sPw1Jl/U8w/1nwjhLubi+kXCKBhdlhCsdIzKdl2uZ9S0pBw3mI5JBB6xUfFXwmpMkzhM31I+obreF7wXwvL4cK73y7aI5hDGgZquBXRsRETpqma424ytHiATcDjT7edOcQzEgRcfFeyTZ1Okf5aj4FX7pFdwnMYZgPgWpJjvx/uxqNI4/D1otNWgTactKgkqvqlIGJWLzoAknju4PE0uTuphlvQHlHELYcCCT5gdILtqCE7wuArFN8Hr6yTYNg/iZ24XEtIK5igWISMAcFEkAczhA+1P8PalVnmFKcEzq8AFpDtxSeJiw2DtUSJaglUsKmF1lRS7JolLX08R4qOQjs3bst3VMl/7Hc6s8aRdeHFljKbMj/7hY/+YEDIibKbNvElL6sYtdnfHhLfNlpV/VLUUnTwqcKPAp4RdjbUlnUoN/MlXsC5iitatnz1H/NSCrFUtaFOLnWoB8ca3Nneya6jFwaNDZ/iOxzaFe4SPpmJKeqg6BzVDZmyisEySJss4oWlRY/20VR7jygcbBsarIlO+EBDkT0lJOZUtTMtDvS5gliL4zNo+GbXKU6AmaAxSuWWcEApUK0cEGj8YWsWVHJKBYTrNuqKTfrf0giyIFHxP5EUydobQQ4P8SRcUlSpif8AQXT5RyTta2pqmVMD5WVFf/yaJ/F9nS/m2qaLW1ywFBrl/j2UItJaQkOSEgYmgZwHJNBRJArUA8sjapttUxVJnpcukmWqUH0O6B0iEbNtMwurdSc1q3j5b0GiXrB53JfxizQ2zbkhH0+MijBgkXH6z/2vFEbXabYr5csOBgPDKRqXJ/3EnACrQTI2AgF5ijMOX0p6Aueo4RYC1fLSEpACRcmgSOAF3KDeK8J/Fkyf2dfRafC+wJch1FlzcVm5Li5AZx/dedAWg7aieEM2LaBMdQqGF9a1HK9QixtcpwXur5Fj394d2ZuKi6RhdoA79w6H7wotdobJUVUS9NNaQorgHr3zI4ZkZ+f8RyU3EqP9I9y0Vto+K1H6EAakk/ZvOOhs85RbNgZsRT7YlH1KCeJAjBWnbk5V8xQH9PhHkKwVs/YNomgK3QhJxmFnF7hNVEatXzhWVoaOd8RSRcoq4Bh1LQENvzZh3ZMreOjrIfEtRI1NInsnwbLBBmTVKucJASNQ7knDI8MNBZ5CZaQhCQlIuAzxOqsyamJ2HqjN/wDh9vmvvDdGsyWAeSFesKV8JWg3rlAf3KJ6BHvGqCuMPC++/eFsMobH8HpBebN3h/KkFIPFTu3BjrGglSwgBKUgJFwSAwxcAXephfMhwVyhNjHJnd998IcJ0RqEM3O+/WFYUFfP79oGtFuCT4iAOPbQirSIZyQb/eEyo8J0T9Y5Pn+HjyiktSBK+le7/SGIrp+mKu07Tmu4UAMmHreesYyyJcOvHic+ou7YhMxBQsBSSA4NxxzvxfCkefbb2MJUwhBIQydwEuwAAIJLk1B1Zncxbz9qqxYnP97oqLZbCS5jNzs68WNxZkviO0/LVKapB32zCaKB5KPOLpNuQuW6S4IcfaKraNklqmpXNmKQghSZigN9RZ1oCEOAVFTpqQAKm4xVydooleBCFKSCWJ3d9iX8W6GBqS2F1WeNdNoqjJ5nHI1Lw0JU98L5YgCwbTlzCz7pwfHQG54t0IjOVp9OiDUlaYDabEF/VXyPFx76Q/ZfwrZ5w3CtaJgJL37yb6A+EECl3LGDwiHBBDEEgguCLwRiO9DCjNoMmKM19l3sf4dlypcyQ5XLU58TOywEqS4b+UEGl5iaTYUyQEpdk3VJoScTXFm9oWzto/NDFkzAKgXEfzJGTkOMCeBJC3Jqe8OUbrp504tOmSy0JXeKwMbEnK8lodJVulxhAfxDa1iUrc8O8d0KqCAaqKSLiWNcONx4SoW6Rnto2jfWQLkEoHJXiPM+QEAmY0OQkAMLhEM1UYXZ6ShqqGTbRAM+e8K0THgKbMi1Ed0jYfAyDuzVn6XASNUpJU2dFANqY1Ck1bV/NjV8KcPDFX8F2bdsiFAh1lStHKlIAuuomLmYPN6G+oUQl3xz062cUuuwKZZiouMKZcPIgwoln7z+FzmQ1SCQebiFDJpkNh+FZ66r3ZQ/qLq47qaclFJi/s3wjZwPGZizS9W6NW3Q46mLoq7aOg9PKNtjisjsey5EqsuUlJH6qqUOClEqB51gonv74xDNnBIKlFgHcn376x5vt/48mTCpFnG4io3v1mg8TEMk354Y3TY1Fs3+1dsyLMHnTAjIGqlcEip8xGdmf4jWYOyJysKCWOjrduLcI84EwqJJUSTfeol3vJpjjBcmWO6/iIc6OiGCzaz/APEYf8OzEjNUwDySk+sQf+oE/wD+Ohv/AL9njFHZk5APn3WLCVKBvrxjJ5TdfGiWMr49nEsZMviVKT1qSekWlh+I7QupTJbIKWeJejvGeXZkGrRxMwJF90L8r/Ra+NH/AA3EnbR/UjmD7NXrBkraks/qbiPeojF2S2uGJpES7aUHdUXBdjwvfJn9+E/lkhv4kH9HoP8AEov3x1fyFYrbbtLBNNcfxGYTaiMYkNreFLNJrg4/EUXb6Ez5pMATzEilxCtUc51xQFPEV82XFwpMDzilK5RV9Jmywp7gFLABOjlPKNIddBOWsWzH/EaDupBBCS9cy13R+pygnfXN/h1JZJQCjdSN1Kt5yhSQkAHwlKSkgVBzjS/EFmlLSZJBSakEXgi5nx0xqMYwYtE2zkp3QtAUFMQSlxcrNJjuh5R5E5OT2NXtn4fkrkLUhIFoSFLJoPmBISVpWl2xoRQeGt+8BsG1fMlVLlJ3X/mDBQPQgcorpu1rTaKCWvxAuQDulxuuCWagKaqxVV2It9mWMSklIv3iVY1DJZ6fy5RGXkem/wAS9+BwEJoSTCJjkPSoahRSQpJZQLg93+90aSzWlM1AUzYKGRy4VcaHjGaWYiFsXK8aDXEG4jUaXj941hOjDNj3X2amTZRcCpr3UVKPUmv7wJtazKXKZKd5SVBqsaUJqWNCaRIm3k7tG3kpJHEO3mIlsswklL3intFymro5seF/3MNaFFJIIIIvBDEcQaiAJ82+N6iUi0ywFoCqUe8PgFBinkYw+3dmLlEKoZSj4Fi7PdVksDreMQFGmdUsjrpS2q0tARmlRASCVEsBWpNABxMGT5bwf8IWDetkq7wH5jHHcYpHEqKR1MbpJHJOcmeoWCyCWiXKv+VLSh8FJ3Upci5yUE3FgR/ND3LFQvIoDmQKKYYFxj9R1dilXAHGhvY3in8vT9IrHDM3ju8iHvBCTvDEtcGarly0SQiYBH6q3teCzm+98cvc9ittakLO8ZYWCAQoFqZfUMX5NChBqbhJ79Wz84cT37Nnpxho77uujGf4ibfMlKZCFgLWAVZhBJA4AlJzdmqCptTiStlF8cfES5q/lS1qCEEvuuAqhSRqwdzrGalSbmF90Cldef5ghE0M3f7wpHVjSSLGzDToB5m+DZQT2Ip5No1fXHnBki01jGSZ1QaLaTTh2RBSVtd33SAZE1Kr6QWAPeMGbJEgmOIHnKdxD0gxCu+BGqRJZZhED7ZmVTVvGki+roW4oGzNWu5Q9Cr4hXJUspGAL8aKDf7jF/uxSVqkWtnmuBmwEToVHJUg7tIeJG6gqUa5fjAxk0aeIektwhi1wzfcFg5agzxasRWe1ImpDHyYj7wSiJE4mxFbbOmYhSFVBDHnEBUQWN8ShcR4XSZV2y0qCALQWWmgn1+XMGG+r/hTNVUVe71VR2mVP3XNQr9WYJeiqPxBMa0qitmbHkl/BuFV+4pUve/uCCArOsdUM6/ZwZPhP2DOK3DLTMmESwhISg5iqhKQm9TqUq52Jc3kw7ZqVFAKgxLk1diamuNXhlm2JKlneQllHEkk/wCo1g9AaJnNSVI0wYHjdshWhjCeHKVEcZnQ2NKoFtJoYnWqIZMxloJwUn1EUkZtl9ZJBSiWJn17oB5BhzYB9XgiVMCVBWLxFNmA+LTv2gG0TYXoVXCysMxKVkCgDt1cQYmxFPzEoN/iRo9QHycNwjNCaXeLaz7RIIOlYtES+jOfGOyQlcuaBu/NB3wLt5O66mwcKD6gnEwX8O2YS03VIrqcn5Dyix+I1fNTJGI3z/q3QP8ApMUSLWqUCCN7Ij0aN14ck3/I0iZ7VxLZ1uqQ1Cwxe4QvmsGBPHJqgUFRhjib4xG0dqTl/Sd0aXnQn7NCs0zeqCQrHPmWc3QUJG0XaQDf0o+uPDk2EKMVNXPSWTMU2rG8nFQJhQD1Z7iDy1yy9fOPDPiy3KnWuZMVRyN0V8KCkKlhv0ncIcfzbz1ePbJqvCrgfTvGPnv5xUN5RcqqpxiakhtfaNUccPRxVCTNiIGE8OjZBSJjEZecHS5r+/30ipSYmkziIhxNIyL6y2jA0OcWqJtBGal2lJDFuPeEWVkWVJr2xjnnE6ISLj51YbOrAyKQ6YqM0jqR0Ka+DLNaBAaUhoDmT91VIqrKbS6aRNpEd+aDQ3EF4pkznAgmzTN4Fy1DXlEOJSY1NsIOdb4DTN/zFS6bwqk0eoCgOhaHSnA6QHLQROmLxCj5eFPoItIuLNBNLywvQF8WU1DwJ8jDZRgK0WvdkpSb1BKUjRJ3n6U4kQRYy4jKaJ/YS0OEuHhEOqP0k8G9IgLBJ692BPnViXas0Ubm7ghsCOcVyZsaJE2FlcMUuIDNiNU2GkS3ROpUNsq0pVvqw+kZnEti3qdICn2puJ7eCLBLc3VxPfpxjaEa6zkzTb/ii1lzd5INQDnxI9oinGDlpADC7u+A5l8Zcs3S50jkpcwfZpDq0FTENisqlKAaLhcpqBqGuvTvSNIqzDLPVAE+VvEnvQQDarGDFwRnTCreXY4Qxct42OLYys+xQOqy1cUIxHvmI006zaQOqzNDK2KNajTeS5a8BFdfEHjkWs+zB7u+cKHRW7PTbSCUKAFSlQHMZg9tHzulVAeH3j6HUtvbnc1K+d5jwn4isAkWqdJwQs7uiVeNA/0KT5xcTmgyueOPHAYezxRocC4lE1iDEJlcIdLW/hPL7RLKRYSyHpccMjF1YUMgcz6xQ7PFT18jF9Zl0A0Ec+Q6cQShUOWaRGM4eu6MqOpMUud5R2dZAslw9H+8CPTnFpZxTyh+F+gFiDOMAW9x1DGDpaA8DSZfiWdQ3JKB7GJgqFIeNcCxKERS7CN5S1KcOSAaAYkmEmdAm2LcEobPDPId4AwlYPgBa7R82c+CaDn2/OL2wXRn9mS8TebzrjF/ZlARMxx8LJKqQ6hFSeRI9IDXN794jVOMRQEto2ahQFVAsKu5LZv+Iq17JmC4pVzY+Ybzg/5xjippik2FFHaZS0fUhQGbOOopAX8QVUSOZu/OMaf5usRTjQ5lm4lx9otTS9REsUn4yqsWzSS5xIqe/wAReWeSE3YxJLQBDFU5QpTcuChgUOv0dNXA4LmFMVCsqXMNIJF3YAEp1iVoZITSHtG8VSPNzSuQ1URlDXebnyeJjDSmKMiIjT1PoKRGqXBBENKR+1PSsMVgS5QhQQoa+n2hQx2axSx68TmMx60xjyr/ABNsRTaEzmHjSASAN3eQAG6MOUekzVEcrhhfgHA8jjlXK/F9h+dLaruCDe5G8ABQfzENjS+KRkn08xRWEoNHJ8soUQaEX/eEmt0WbenQvWOrD3UPrEgAV3WOLkKvETZdBezVPzB784t7OcOkUdhmMe++xFqiYKV4GMpo2xvhYy1Zw2fOYQOq0hsIrbRat4sMYhRs2c6LWTM8MWMmYw5RWWYsIOSqJZtCRKlTJ1NTxNT5mFhEa5oEBz9qITi5yFYlJst5FH0MmzGqbs4orVOMxb4Yd5xDbbXMmlgwTkK9TA4EzBo0jCjmnnTZd2WYByg+TaRGYKprYdD5xNKtM1N4pjQwniGvkJGm/ihHRNeKuVaXAMT/AD4ycTZTsNC4QmvFdOtgGMNs1qBq784NR7qyzC469U/3DyLwIJ4h6ptHyIPIFz5RNF7lmZkMVNzgVU2IVzhiYFEc5InWrSD9myHLm4dtFfZJZUa0GX6jweLuUQ1LsMmxHHN9Y2jA4c2blIMC46TDEDv8w5I7941OJnTCJhbsdaAkaY4RDjCIhiIFDjHYcpPbQoYWWs0UJww44keUVtskFVMVAktQAVNSxKjU+cWsxFScc89CTrlAk5D3XYX01Bwx8oZBgdtbF+ZUO5cJUQKsaihLh3pew64+0Wdcs+INrh+DpfHrtrsbvS+/nfSlWpFFbNi7/hbF91gBXFmrjFJlJnnwmxImeYurZ8LKFU/jQV94rJuw5wuS/C/mDD4WpsjE6rxP/FhsRA42ZNvIbvOJU7Hm93wqRX5KI5loJxhqZ1dYKTsJeJguz/D2ZPC78wUhbsGlbTUP0vBSdozDckDjf0ixk7EAuHl36wXL2U1GD+kQ0jVZZf6UfyVrHiL8PtEsnZxwF3GnS6NDLsI0gqVZNHyy6Qg2soJVgJ79t6DpWze8ejU9YvJVl0fRg/TCCUWbTvjCFZQf+Fj81433xwbKF/sPxr3WNImy9/tEibLW77wCbRkTsEGocf2t/wBprDT8PP8ArU3Et+8bZNif9sBqaw8WPHswBsYQfC6cXfWvlf6Q4fC4FxI4Ejy3+Eb1NiHW6J0WQDT7+8Potzz0fCyv+YvvQ084m/8ALs8fSoHimp6EDyjfizAe3GEZIyw6QnGxrK14YJOxp5oSkcAT5mgh8rYjFyXOZqeVSBya+NsZFwa7t9YZ8gd8/vDSSB5ZP1mZlWAin7ktefxB8qTrxPtrd5axZmyi7r3nHDJ5sev5h0RsDJRn315w4DrE4l1PG/Hge8ocZXft5wUTYPuwimCCm+GKRBQrIVJ77EMKYmKX77wjikwUKwdQGXfSFD5lIUAi4UnHpkKw1UsfvjnE2nlz/eERFEgqpTwMuyhu8/IxZbuffKOfL775wAVC7JXXh28CL2YDh3pGiMnv8Rz5MOgsy07Y4OAgZOzCnCnH0yjZGQO24QwyB0uA9STDoNjJIsWDAHy1bGHiwaDhR/KsaVVjBBDd6Qz+DIue7Op5491hUUpFCLCdfP3NIkRYO2b2i3EgB3AHId3xOmUxGrvwPZiStirRYP2HbRMnZ47r1iwQihbsRJudAKZP28AbAKbMLn7ci54mRZu8Og4wciVidXa+/kBD5cq7PFssiekFBsBfIz75Q9Mnlpj05wYlAq3L8Qgi7LHjrmKwUFg25p3z4ND93s+3lBEqVc/l1o3PrD/lsA9+WLZthdBQrBgjuuGfeMP3Munp6ecSql3gX3aw0AMMseOZgoLIwnund3pHFJ7ufrSJ0Jz5/jzEcKaDUs2erQwsHUjv794RGR+/790glcu8Dl5e0QHA/pboTCAiUnvvi0NKfRoISl76DEi+43Ndzjm7QPiWbOl+kMVkBQOmP2hFPfvnjEu5eMj1w9IbrplVPKGFkJT33zhhT2e+F0EoS/4rXH7MRnHVS7nvN450Oh9WgoVgakY9I4oGCinqCRxz9IjIv9fY5VLQ6FYIogXwoMEk4XcT7KrHINQ2DB2PzCA4dc/xHBhlDtPXDhEiOtox8oQD6R3XAd0MIAsaVNYAOgY+Z+0Ju6PQZc47ljpD0gdSKY5+kOwGBGEIIzuNxv7pEyLwb6UA9zHWYM7k3tofvDEQlHADW7EUHSHmVxbO4XjMvErChwhqb8H1466CGMhVZwq8DyzNxiBVmUDmMa+LplW/yiwCeTdbu/KHblzi/t2wgqwsqkDLg3X8xIlOX4g+bZ0l3wxo9IEXLKQ5FMwacTiDVstYiqKs43u/lEgIx6XY/mIx30h4VR9agwhkiRc9KcmFT7Q8DHk5vpkeUNSwbI1GkLeqVZXDm35hiHs3eRv6E9DzckZeQrkLoYDcDeRecriPSJMiQ4J4HAuCNRXlDAbRqXds5vxaOlD8av8Av16R1j4s01fMM9eTQlkMkYF35NDERmXwflnpqI4xHuW5+npEhP1D+W5N1x86B4clONKB9NRmKv1gGDN0xxOcLc66ffXXPSCCgUyNxxSzdYiW4CrnF/uekFCsgXJbJ/PPDkY4JRH3bkO8mifFOAPUl6+3WOJrRmLszORhWowo4ywh0FgagAP6Xqb+D95RzduJ5YlhhvCt58zFgqU+8blJvapGGLbw0PpA6pBBLCrOAPpPDLhBQWRbrX30yJzw1Y8jHAMqcBy7bDg8dStgNTVR9H4v2Yk3BUYJoSSb7g7VAckdMmhoQOz0wxapoWfdv8o7ugArUQAkElRLAAXkqNw46xPNk3vckOXNQB+oLva81pHlHxz8WG0EyZKv8hJqq4ziLlH+jLNgTgA/ASsJ23/iEsTSmzS5apSaBUxKt5RcuoBJG6k4A1xxYKMGoQom2a6o+gxwPf7w4dvpDAeJ4dYclrsde+ESZDwezDk53a5tEbvfHd6uvUD3akICUdIe+uflT1iNBvN/pfdD31OHCGA7ewftmIh29UjHDXKvKGHWozxEdQljumuRx7/MAiVPniMM39Idu8r6X3D81hiVYXAXnyPAV8o6C4e6/iad+UUBKk4D7XJ/PvHR014Njy7aGKLDSt2Fw94e1ciblDHCurAdYYHQLmpW81fjyhyU8sn4PQ48NI4kF7mOOV7v3hDpZ8sT0u6XwAQzbECXFCHalKFvQi6kCKlqTQ0fHDke7otHfplo/qPWOkAg3EdRcFeuMJqx2VJOOAp3xh4xI5j143QVNsD/AEngKNyPLF4DUCCyhVgcuxUxNNDsektjyN2B75RMlTVo9egO96P1gdEzB9CTzB+0ITMzk9OXfCAYSkliBjeTx76m6OE0OIa40NzuNbg0RhTjBQ0cGt7dBD0rx+oAmhvBy4UYwxDtzC9rsFC+mrO/tDm46OLnpf8A3AHt4aTnUtTQXPpd5GOG9uRbB/CehD51hiHFWNS1wuZ/TAdIaknOr4YY3eghIJ1fRQoTociO8GgYs4p9NFAOGDc4AEE40alL0k4NiCwV9xC3Wz5+IMCwY8WPdJJaTnQD6hi1Sc8qaG946lQvbdSBdidOOFNIdCGEsN0MBjneAz5O46awlAFQIcBIvxxN3txjhBOZ5irBvO/lhdC3bn5PQ0LliL6kaVMMCFcrRgcD9J1Cv0qvNfxEc1aZYK5hCQgFRUs7pSkOKruKb/KDLTPEtJmrUEoQHUolgnGoxo2pujxb4y+MV2s/LR/l2cFwgUK8lLGFGZOGLmDwcVZN8bfGa7UTKlKKZApiFTWxUME/09chjyYXOFEN2bJURzBChKMdgoD6AA1PtHd9wDqIUKAxHC99I4SwJ7yhQoQD1UbL7Q+Wq9sXFbsB7x2FDQEu7QJ5+3vCK/Fw9o7CgEMSHBGNfI19IkQHZqFPQ91jsKAZIEkuKVdzze7WJAXIGXm8KFFCHyFOo8W9vaGg+DgfRxHIUAExVVKsCQlsj4qwjQJOOelRVuHKFChgThLqKcKHgSHfvMxFNlpUgFQBL34hwXIN4e6FCgArrZZCh6gpoxq9TcRoReDV7qVHQCcWFWjsKIaKQ4Kv/mGPB4eV1ScS76tj6woUJDHqWd8jFvPdDQ1K/wDLJyIfgaMPKFChiHoIUFHAAqH8wo5L8RD97woXjvMdaPXpChRQiRvEtGBDcN5LjlWBlqeWFUoqurwoUMRMo+If1JBTmmrB86uecSboTu5Kdxh4WN3dwhQoYjxT44+L5ltX8sOiQgkJRitSS3zFtQnIXCMoTChRnL06Irg14QhQoQ2SEDEAwoUKKEf/2Q==" alt="Pediatrics" class="card-img">
    <div class="card-content">
        <h3>Pediatrics</h3>
        <span class="specialty">Child healthcare specialists</span>
        <button class="view-btn" onclick="toggleDoctors(this, 'pediatrics')">View Pediatricians</button>
        <div class="doctors-list" id="pediatrics-doctors" style="display:none;">
            <!-- Dr. Ayesha Khan -->
            <div class="doctor">
                <h4>Dr. Ayesha Khan</h4>
                <div class="rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(145 reviews)</span>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> Children’s Care Hospital, Lahore</p>
                    <p><i class="fas fa-phone"></i> 0301-1234567</p>
                    <p><i class="fas fa-clock"></i> Mon-Fri: 8AM - 4PM</p>
                </div>
                </div>
                <div class="action-buttons">
                    <button class="book-btn" onclick="openForm('Dr. Ayesha Khan')">Book Now</button>
                </div>
            </div>

            <!-- Dr. Bilal Qureshi -->
            <div class="doctor">
                <h4>Dr. Bilal Qureshi</h4>
                <div class="rating">
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(130 reviews)</span>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> Little Stars Clinic, Karachi</p>
                    <p><i class="fas fa-phone"></i> 0312-7654321</p>
                    <p><i class="fas fa-clock"></i> Tue-Sat: 9AM - 5PM</p>
                </div>
                </div>
                <div class="action-buttons">
                    <button class="book-btn" onclick="openForm('Dr. Bilal Qureshi')">Book Now</button>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="card">
    <img src="data:image/jpeg;base64,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" alt="Oncology" class="card-img">
    <div class="card-content">
        <h3>Oncology</h3>
        <span class="specialty">Cancer specialists and treatment experts</span>
        <button class="view-btn" onclick="toggleDoctors(this, 'oncology')">View Oncologists</button>
        <div class="doctors-list" id="oncology-doctors" style="display:none;">
            <!-- Dr. Ayesha Siddiqui -->
            <div class="doctor">
                <h4>Dr. Ayesha Siddiqui</h4>
                <div class="rating">
                    <i class="fas fa-star"></i><i class="fas fa-star"></i>
                    <i class="fas fa-star"></i><i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(110 reviews)</span>
                </div>
                <div class="action-buttons">
                    <button class="view-btn" onclick="toggleDetails(this)">View Details</button>
                    <button class="book-btn" onclick="openForm('Dr. Ayesha Siddiqui')">Book Now</button>
                </div>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> Hope Cancer Center, Lahore</p>
                    <p><i class="fas fa-phone"></i> 0301-5566778</p>
                    <p><i class="fas fa-clock"></i> Mon-Fri: 9AM - 6PM</p>
                </div>
            </div>

            <!-- Dr. Salman Rafiq -->
            <div class="doctor">
                <h4>Dr. Salman Rafiq</h4>
                <div class="rating">
                    <i class="fas fa-star"></i><i class="fas fa-star"></i>
                    <i class="fas fa-star"></i><i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(98 reviews)</span>
                </div>
                <div class="action-buttons">
                    <button class="view-btn" onclick="toggleDetails(this)">View Details</button>
                    <button class="book-btn" onclick="openForm('Dr. Salman Rafiq')">Book Now</button>
                </div>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> Metro Oncology Institute, Karachi</p>
                    <p><i class="fas fa-phone"></i> 0314-7788990</p>
                    <p><i class="fas fa-clock"></i> Tue-Sat: 10AM - 5PM</p>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="card">
    <img src="data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBxIQEBUQEBIVFRAVEBAPFRUVFRAPEBUVFRUWFhUVFRUYHSggGBolGxUVITEhJSkrLi4uFx8zODMtNygtLisBCgoKDg0OGhAQGi0lICUtLS0tLS0tLS0tLS0tLS0tLS0tLS0rLS0tLS0tKy0tLS0tLSstKy8tLSstKy0tLy0tLf/AABEIAL4BCgMBIgACEQEDEQH/xAAcAAABBQEBAQAAAAAAAAAAAAAEAAIDBQYHAQj/xAA/EAABAwIEAwYDAwsEAwEAAAABAAIDBBEFEiExBkFREyJhcYGRMqGxFELBByNSYnKCkrLR4fAVQ8LxM1PDFv/EABkBAAMBAQEAAAAAAAAAAAAAAAABBAMCBf/EACURAAMAAgICAwACAwEAAAAAAAABAgMREiExQQQTImHwUYGxMv/aAAwDAQACEQMRAD8A4akkkgBJJJIASSSSAEkkkgCSCF0j2sYLvc5rGjmXONgPcr6HngFPBHTtOkUTIgeuRoF/W11yr8k2DdvW9u8XjpmiY8x2huIh7gu/cXScaqbkpo0xzspamTVNikUEj9U1rl3oqUls+e7Fg+JpLOutW+XRYziV1z6oCp1LKykry1w1+8PqtpgnFckZdrmYDsdVjDgcoPdc0je9y35W+ivKCgs3y1Jvu6xP9ELfs5xRb6aOm4RxdFJo+7T4/wDS0cNVHINHArj0MYa3NztqT1v1U0eMPjPddYeaOJq8B1efDY3j4Rfx1WcxrhQP1G+uqAwjixxADiCtHR8RMcbHdLtGfC5Od13DD2clUzYS4cl2rPDKNbISowGJ+xCexbXtHFX4aeiZ/p56LrNRwp0CE/8AxrzsAPEm390bQtSc1bR25KCSjLiusQ8CN/3JD5MaP5nf0UNXw/E3uU8Xg6R95HH9kHQedrpbRy1Jzmkw49FqcFwYkjRXIwaOAZ53sjb1kc2Me7iqjGfyh0lK0sox282oDrObA09STYv8hp4pHDaRq67EafC6ftp3WNiGMFu0e79Fg+p2C4bxTxFNiFQZ5jYfCxg+CNnJo6+J5lC4xi01XKZqh5fIdLnQADZrRs1oudAgVyZNiSSSQISSSSAEkkkgBJJJIASSSSAEvWtJIAFyTYAakk8gvF0X8lPDHaP/ANQnH5qJ1oQR8co+/r91v83kUDS2bbhfB/8ATaBsLv8AzP8Az037bgO7+6AB5gnmqzEJ7lWuMVtydVnJn3XaRbixkbnJocvCV4F0VTA6V+iyWPG5Wom2WaxVlyj0GaPwVhqrt1a42HN5Df4WgfVaDDsPdJCA9wijIDmht3SOvqXEE2APK+tlWUVHm06kD3VzDAGg99z3g3yN0sPDqB5JL+TPDib7oOpOG4Ld4yOHUyZb+jbI5mD07B3W3O9nnOD5XvqqB2MMacpzAg6ggk/zD6Lx+OsH6R92/wDIo2jbWJewqTDomk5C+M35HMPYqGWtfCQS8OBuAR4W3HXVQR1jJzlBe2/O2do8TYaDxVZiUQa+zXh4tq4fDfoE+SObqZncmqpeJXDmryj4nd1XM43EK2w+Qo6ZzDV9NG7qsfkf942VXLisg2e4eTiPogmHRVmKVOUhvVGh1jUotcd4nqIKRzmzyiSR3YsPaPu0Wu9w10IFhf8AWCwknEla7R1ZUkeM8x/5Ky4zf3aZnLsXy+r5C0/KMLMrN+Tzcz/bHyyOcS5xLnHckkk+ZKYkkkZCSSSQAkkkkAJJJJACSSSQAkkkkAJJJaLg7hOXEZO73IGEdpKR3W/qt/Sf4e6AHcEcKvxGaxu2mYQZZOg/Qb1eflv59jrJmRRtiiaGxsaGMaNgAvI44qSFtPTtyxMGnVx5ucebj1VJWVFyukivFiB6ua5QTipHuUZXRfEDF6AlZOAS2UzBDMNFR1kdyr+YaIals2TMeQPz0S5HdYtrRFw/hT535Ixt3iToAL7kqXEMElpXl7z3eTmEkeux91v+GqFkcQcG5TL3zyOUDu6eRJ/eTcRpMwcXsJa7dupzNduQNx1XDsxWt8f8HJ3yy3LrgtOti0X9eZQ0sx/9cfmA5p9w4LTYnhfZOMYuRoWk21adj/nQoKLDGvvmdltflmJ8BqFw6M6+PT8FHnLtC0eud38xKlkgAaNO84+AsB/f6IoUtiR0Nk8QFzg0Ak91oA1JJ5D1K6mjOcT12AiBWOHRarWUHBYLfzz3B2ndYBp4FxBufIe6mq+Fmws7SJ7nBp7zXAZh43AH0WqpG+OJVFK4WCy+JSZp/AWWmxF2ULLTC77rpC+TPSRNxrF+apZOscsX8Ds3/wBFlF0HEqX7Rhjw0XkgeKgW3yWyyD0BDvJpXP1nXk8n5M8cjPEkkkicSSSSAEkkkgBJJJIASSSSAEkjMKwuaqkEVPG6SQ62byHVxOjR4nRdY4W/J7BR2mrC2acahm8DD4g/+Qjx08DugaWzJcFcASVeWepvFSfEOUso5ZAdm/rH0vy6iXxwRNhgYI4mCzWt0HiT1J5k6le11eTzVLUz3TSKceM8q6m6r5HJ0jlEV0XY4GFKy9IXoCTZZEjQE4BODU4BZ1RTMEEjVNguHGedrPu/E4jTujfXx0Hqk5q1fDVD2UPaH45Tcdcg299T6hZOx5vzJdR0ge4G1yO6Lmw8gNk3FmkHKTe2vjbmjKCzWhx0JdpdMniDpdTe/suavo86X++/Rj+JYfgeGkk5m6Anbr7rPSuOXK6Mlup+EtcPW2vqt9irZImZogXHNYjLmIFtfmPms9Pi84+KK3mxzVPWXTLJv8+TIty3/wAurbhqlaarPuGRvl9QMv8AyUlVihdo+Jh8wD9UZwwQZXnIGjsHmwvr3m9V1GbZztNa3v8A0a6SK8WZumxLud+dh0UjaUA3dc5263N2kdCEPRW+AlzmPuW6XF97e990XJEA27jZwNrXNrqqa2TXOno5XxPEYpXxn7ri3zHI+osVmXDVbX8oEP5xkw2ezKf2mafS3ssYQt5fR3l/WjS8I1vZStPK9iDqCDoQfCyzv5Q+FvsM/aRAmkmu+J24aTqYieo5dR5FE0EuVwXS8PgixKidRz6tIuCPiaRs5p6g/iNiiiX5eHnCpej5/XitOI8Dloah1PMNW6tcPhew/C9vgbehBHJVa5PIEkkkgBJJJIASSSSAJKeB8jgyNrnvcbBrQXuJ6ADUrofDf5LZH2kr39izfsmEOnd+0fhZy6nwC6PheGU1C3LSwtjuLF3xSO/aedT5bJVFQSg6UjaOCCkj7GljbGznl3cdrucdXHxKEqaolMmkQkhTN5RHNLdCPKmeoHBMphETkxSlq8yoLMaGAL0BPDE7KuWyzGhgCe0LyykaFNdFcoJwyhM0rY+RN3Ho0bn/ADqtu+mB20AAAtsByHyVZwtTBkTpju67R+y3f3P0VkaiwNxvY+/+fNYOkltkOe3V6Xr+ssoWAMzO1tYjnYeCa45nXt3bAFTU8ocLEWbbn/n+WXhj1tbRcVfXRD7eyrxuD8y4gn4mnxHL8VkZjKNnPt+05dCq6fNG5nVuYddNfwWYrKZwbbdvhqFB8m3NbK8FKp1szEr5T94+uqJ4dYftBzakwO/mbYeynfFZSYBGftBPMxvv8ksGbdI0a0i7wuYWdGRe5Nh1urGaFjI8uXvbku1JKqaKURyX5dpY6bHzVxNEZ3WG1vi5L1sV7RjnnV79eTE8UUTZoHNY3UEyN8HNFyB5i+niuauC7BXRuDuzAAa0aOFr5g4WJHr81zTiKh7CoewDuk529MrtbDyNx6KmGd1O0mVcZsVrOFsTMbwb81krI2imylb+UKUmuLOp8V8OQ4rTAOs2QAuik3LHHcHq06XHkdwvn7F8MlpZnQTsLZGmxHIjk5p5g8iu78I4vcZHHQonjXhKLEosrrMmaD2Utrkfqu6sPTluPHk8f5GBzWj5zSR2M4TNRzOgnYWyN9QRyc082nqgUiMSSSSAEkkkgD6RkBQ0kZRjsQh8fcJn2yA83fIpmqK58SHkjVuZoD94+w/qmlkJ2k9wUGksonxqIxLRfYoj/uN+f9E9uFsOz2+6ZRNpGZ7BeinWqGDjkWn1CY/CHdEiiMqMx2SY5q0EuGO6IV+HHouKLsWWSnyJ7Wo91ERyTqejJe1vVzW+5AUeTZdNzo1lPF2cUcf6MYc7ztr81MyS7c1raa33T61xF7dAB9f6IRlSQQ4jTaywzVxPKlOlsuQy7bt+LQp72HQ3Qsb3NF8tgdbclOx5fsNNr7D3WFWn17J3LRPkBII3Cz9fALnzIWgYQANdeaGq6EuJIBIOv3PxU/yIeSfyux4MnCuzJTRNXmBQntiRyYR7kf3VvU4Z1a/0A/AqfC8PMbC4iznm9ugGw+ZWPxsVq1tF+TNDjaBKelaXHmTc6gEb9P8ANlfhtm5QNxp/boq2Cj7/AD0B520RlG45iHOOUAgEjS/ifIr28HSI/kPl3vwZbHIH5iW6XLdSdNxe6yPHNGe7KR97s/QtzD2LX+66NjNGSLrL8U0eekf+kxrH+BGcA+wv7qiemVYsiqUv9HMHNXsaIkiUeVUyzSsbTLfCKwscNV0/BcRErBfcBcfhdZafAMTMbhqhk3ycfOd+zX8U8NQYhF2c47wuY5G27SMnp1HVp0PnYjg/FHDFRh8uSZt2EnJI25jkHgeR6tOo+a+iKWrD2gjZeYjQxVETopmNkhdu12o8wdwR1GoSPGyQfLyS6Hxf+TKWAmahvNBqTHvPGPL/AHB5a+HNc9cLGx3GnikYNaPEkkkCOsurT1TDWnqqozJvaplCLf7ceqcK89VTdqvRImbyXjMQPVER4m7qs+16mY9PRRCRpIsWd1RsONO6lZVjiioyUmUzjlmuhxt3MouPFAdwFkYbo+BrlnRqsEGlbUMdyCIo4WGRlv02n5hUUDD1VthrgJGftt+oWFCqOKemX1VT5rD1QjWE93Um5IGlh1VlJIM1un9EmhpFyLHqscmPkRzkaRVXktaxsCjo5SBa+inEbBfc/wCbr1sTOd9fJQv49J9M6rKq9EEZ5nqrEHQaXFkMXMBsApI6sXsAusMKOmzG912kPdH1vYoR8BbtsjWzCxPKyHkqhzVXGTmHQwUulwdVDIwAAk6c/BPdV8h/ZQVb2mwBv1/6W069Gsqt9ijqA8FruWiAxXDc0UrRreGS3sVJDMSTmtfYWsCdFP24Yy4+G0u+vIuC1ns77h/k5LWYW5vJVktKRyXUnSQyDvsHmNEDUYDA/wCF1vMLSej0H8pP/wBI5r2ZCnp3kFbGfhP9FzT62+qAl4WmGzCf2bO+i0TMayw/DCcCxbLoTotdTVNxcbLn4w+SM95rh5ghXGHVb2IIM0J9o2TbHVuh6LO8S8F0dfd0sfZzn/ejsx5OnxjZ+w3F+hCOp8RHMI5mINKRDSOI8Qfkzraa7ogKiIa5oge0A/WiOv8ADmWMe0gkEEEEgg6EEbghfUn2tvRefbW9EjNycPzpApsbETHCSujRDGhTMYiYaRGw0iDeQGOAouKlKOZCApAQE9lE0QxUqLjhAURmTTOkVQ2w9hAUragBVJnS7dcNFURsum1amjrSDcHUaqhE6kbOp7RVOJHRpJsxEo+FzQ4Efra2+qY6sDXXOqpeGq4SRmEnvNu5vi07j0OvqpJJctw8Hn6LG/GyBYNU4fr/AIX8VYwscSbOvoPDyUTqwW1JuqaKpGU6aD31XsdQLa3Jvt4ealttnS+Mk2WrKoDXW6ngqbnw1O+p8FSzzi/d2sLa/XxVhQss652y5z4BcRL5aOcmJKdsuSy7ADpsT+AQFdYa5vAD8bqoGMFtRdzu442cN2huw9QjK17mE7ZegAy+ypTVLoyWCopb99nmbo6/LxUZkte7tdtlDDUM2t3zty1PQBeVsDrkW7w31AHXTqtZRtrvTJYzcjKe/a/gP8ClxCXLBMb30ePdp/FAU5DAXk97Zu435+Nl7xBJlpSOcj2/KxP8o/iVEozqd2l/Jm46whER1yp5tFE2ostEV5ITNI2t8U8Vh6rPsqVMydaEGTHovm4k8fePvceye2uB+JjD+60H3CoxMniVBJco0cVTEd4x6XUwELuVvVZlsynjqkiapL40rfuuXn2U9QqyOq8VN9sPVBmzndPQnorGGjAR3ZhqY+RAIa2MBIvUL5VC6VM1kIdKonSod0ijMiZTDCDKmmRDGRNL09FUMJ7RLtEJnSzrlotx0GiRPbKgRInCRZVJXFF1h9cY3ZmnUEEbLTOxASsEsYBAsHsNjld4eBWCbIrXBKxzX2FiD8QNyC3n/wB9bLByPJjVfr2jTmrDrljcoIALbh3rsoYptdQT5aa+KRcGszx2cw6HnY9COSZSyOfcXawb3IuT1DSsax7M50k9B9LA++fLYAGxNiCQERUVDYIwxzwHv7x5nKPAdT9EHNUWYCX5YRck/edbZrRzuqOav7RzpCOWg6AbNXPDj4FON5H34/uietqAX93aw3381o8Kq+3hab/nGfmnA65tDlJ89PUFYUzX1KNwjEBFJ3j+beMjvDo70OvunjjTN/kYeWPryjQSPyu/RdyO1ikJcxPau8y3U6D6bBS1sLn6223vyN+9rz3v5IdsUtwAANbZjqDvr5KmZ0Qck1/ITSO7Z4AvlFtN0BxPV5puzB7sYyfvHV34D0RxqTSQmQ/Ee7GLWu4/et05+Syfakkkm5JuTzJO5WqRxjndOvS8DpW3CqajulXDSq/EodLrRI7d+gRk6JjmVQX2KmjmXeibIy4ZKpmyqrjlU7JUiOyybIpA9AMkUzXrkmoObIpO2QIen5kGTBJZkK+VQSTKB0qBInfKonSId0ijMiZpLCDImGRDl6aXrpG00Tl68zocvXmdM3mwjOvM6HzpZ09FM5AkPTg9CZ05rrm3jZJyUxmCw9aXA42iBz3C7jc2BsSBoNel7qhdQ5j+b20FnH5grT0LGxMaPugDvXu4O2cHeF1lUo2rI9aIO3kicSxo1Gos9zSOhudU44mwavhdm6CTK32LTb5q1hY3U5Wm/v6g/wBVKIoz8QsOneCzcC++faM3W4iZ7AnIB8LDqzzzdfMW8kG8uZdpFlc1uGRk3aQ3y39tlV4o8NaGdBYE7n+i5+sqx5Z1qQXtV72iDEi9Ei6WM0dmtwHGGkiOV2XTIHE2aW27oJ5OHIncaedtK4Rd+aZmQXLQDdz+lgN1z4PTg5dqSLJiTe09FxiWKPnfmcTYaNHQKFj0C16lY9d8TmmktIsY3qSaPM1BxPRsLk9EeSjKV7MrlBHKrnH6bms3msV0TZL9lrHKio5VTxSouOVJmDrZaskU7HqsjkRLJFyY0ywa9PzoJkikzpGTKR0qidIoDImGRBymTmRML1AXppemdJk5eml6gL14Xpo7VE5evM6gzrzOujRWT50s6HzpZ11s1WQJzr1sljf1Q2dLOmazlLSmri1zdTuOYtv5KzdiT92Hc302PXT3WchaXuDWi7ibAK7lgcAGAF5aBqATrzXLSK8WXfkNixoN0e0tP6u38JVhFjrP/ZbzDgfkCsrLTSXuWO9ihw6x1CXBHf2r2a+XF4j/ALl/JryfnZVeKVweA1rS0Al2ti48rnp5Ktp5hfQKKebM4nx08hshQafYkggSJwehA9OD0+IfcFh6cHoUPTw9GhPKFtepWPQbXqVjkaMqssIno6neqiN6NgekTXQfXw54z1ssHWtyuIXQqZ1xZY/ielyPJ9UtktvoqI5EXFKqoPREUiWyfkW8UqKjkVTFIi45EhNlmyRSZ0CyRS50jkoC9ML1EXJpcgzJS9NL1EXJpcg62TF68zqEuXmZMeybOvM6hzJZkbOkybOlnUGZLMutnSonzr3MoMyKgpS9heDqCRbloLnVPZ2qbLXhgt7Uvds1u3XNorTE2yvJOuS9mgXsRysBpsq7hCmEjnkGz25SDuLa3FlqqaQX7NwF2nwc2/kfwsnsqxX+TJOo3jUNd7OUTpnt0dr4PGb66j0XQZAG6OY3XoAPwQmIRw2sWX9rJ7O+WzGiDM3tI9LHvNOtvEHmEGXLQYgxrY3dm0Nblc4gc7C5+QWYz3T2F3rQQHp4ehg5PDkbOfsCA5SByFDlI0oD7AprlK1yEa5StckxfYGxuRkL1WxuRMLlycOi+o5ELxTS5o845LykerWaMSQuB6LgwpnKJdCvY3qTFI8ryEI1yRg2WUUiLikVVG9FxPQLZaRvU2dARvU2dAbP/9k=" alt="Endocrinology and Metabolism" class="card-img">
    <div class="card-content">
        <h3>Endocrinology and Metabolism</h3>
        <span class="specialty">Hormonal and metabolic disorder experts</span>
        <button class="view-btn" onclick="toggleDoctors(this, 'endocrinology-metabolism')">View Specialists</button>
        <div class="doctors-list" id="endocrinology-metabolism-doctors" style="display:none;">
            <!-- Dr. Samina Qureshi -->
            <div class="doctor">
                <h4>Dr. Samina Qureshi</h4>
                <div class="rating">
                    <i class="fas fa-star"></i><i class="fas fa-star"></i>
                    <i class="fas fa-star"></i><i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(101 reviews)</span>
                </div>
                <div class="action-buttons">
                    <button class="view-btn" onclick="toggleDetails(this)">View Details</button>
                    <button class="book-btn" onclick="openForm('Dr. Samina Qureshi')">Book Now</button>
                </div>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> Metro Endocrine Center, Karachi</p>
                    <p><i class="fas fa-phone"></i> 0301-2233445</p>
                    <p><i class="fas fa-clock"></i> Mon-Fri: 9AM - 5PM</p>
                </div>
            </div>

            <!-- Dr. Asif Nawaz -->
            <div class="doctor">
                <h4>Dr. Asif Nawaz</h4>
                <div class="rating">
                    <i class="fas fa-star"></i><i class="fas fa-star"></i>
                    <i class="fas fa-star"></i><i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(88 reviews)</span>
                </div>
                <div class="action-buttons">
                    <button class="view-btn" onclick="toggleDetails(this)">View Details</button>
                    <button class="book-btn" onclick="openForm('Dr. Asif Nawaz')">Book Now</button>
                </div>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> Wellness Hormone Clinic, Islamabad</p>
                    <p><i class="fas fa-phone"></i> 0312-4455667</p>
                    <p><i class="fas fa-clock"></i> Tue-Sat: 10AM - 6PM</p>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="card">
    <img src="data:image/jpeg;base64,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" alt="Hematology" class="card-img">
    <div class="card-content">
        <h3>Hematology</h3>
        <span class="specialty">Blood disorder specialists</span>
        <button class="view-btn" onclick="toggleDoctors(this, 'hematology')">View Hematologists</button>
        <div class="doctors-list" id="hematology-doctors" style="display:none;">
            <!-- Dr. Nadia Sohail -->
            <div class="doctor">
                <h4>Dr. Nadia Sohail</h4>
                <div class="rating">
                    <i class="fas fa-star"></i><i class="fas fa-star"></i>
                    <i class="fas fa-star"></i><i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(76 reviews)</span>
                </div>
                <div class="action-buttons">
                    <button class="view-btn" onclick="toggleDetails(this)">View Details</button>
                    <button class="book-btn" onclick="openForm('Dr. Nadia Sohail')">Book Now</button>
                </div>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> Blood Care Clinic, Lahore</p>
                    <p><i class="fas fa-phone"></i> 0305-9988776</p>
                    <p><i class="fas fa-clock"></i> Mon-Fri: 8AM - 3PM</p>
                </div>
            </div>

            <!-- Dr. Omar Farid -->
            <div class="doctor">
                <h4>Dr. Omar Farid</h4>
                <div class="rating">
                    <i class="fas fa-star"></i><i class="fas fa-star"></i>
                    <i class="fas fa-star"></i><i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(89 reviews)</span>
                </div>
                <div class="action-buttons">
                    <button class="view-btn" onclick="toggleDetails(this)">View Details</button>
                    <button class="book-btn" onclick="openForm('Dr. Omar Farid')">Book Now</button>
                </div>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> HematoCare Center, Islamabad</p>
                    <p><i class="fas fa-phone"></i> 0314-5566778</p>
                    <p><i class="fas fa-clock"></i> Tue-Sat: 9AM - 5PM</p>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="card">
    <img src="data:image/jpeg;base64,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" alt="Medical Genetics" class="card-img">
    <div class="card-content">
        <h3>Medical Genetics</h3>
        <span class="specialty">Experts in genetic disorders and counseling</span>
        <button class="view-btn" onclick="toggleDoctors(this, 'genetics')">View Geneticists</button>
        <div class="doctors-list" id="genetics-doctors" style="display:none;">
            <!-- Dr. Faiza Mir -->
            <div class="doctor">
                <h4>Dr. Faiza Mir</h4>
                <div class="rating">
                    <i class="fas fa-star"></i><i class="fas fa-star"></i>
                    <i class="fas fa-star"></i><i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(65 reviews)</span>
                </div>
                <div class="action-buttons">
                    <button class="view-btn" onclick="toggleDetails(this)">View Details</button>
                    <button class="book-btn" onclick="openForm('Dr. Faiza Mir')">Book Now</button>
                </div>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> GenCare Clinic, Karachi</p>
                    <p><i class="fas fa-phone"></i> 0306-3344556</p>
                    <p><i class="fas fa-clock"></i> Mon-Fri: 9AM - 4PM</p>
                </div>
            </div>

            <!-- Dr. Asma Iqbal -->
            <div class="doctor">
                <h4>Dr. Asma Iqbal</h4>
                <div class="rating">
                    <i class="fas fa-star"></i><i class="fas fa-star"></i>
                    <i class="fas fa-star"></i><i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(72 reviews)</span>
                </div>
                <div class="action-buttons">
                    <button class="view-btn" onclick="toggleDetails(this)">View Details</button>
                    <button class="book-btn" onclick="openForm('Dr. Asma Iqbal')">Book Now</button>
                </div>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> Genetic Health Institute, Islamabad</p>
                    <p><i class="fas fa-phone"></i> 0310-7788990</p>
                    <p><i class="fas fa-clock"></i> Tue-Sat: 10AM - 6PM</p>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="card">
    <img src="data:image/jpeg;base64,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" alt="Hospice and Palliative Medicine" class="card-img">
    <div class="card-content">
        <h3>Hospice and Palliative Medicine</h3>
        <span class="specialty">Comfort care and symptom management experts</span>
        <button class="view-btn" onclick="toggleDoctors(this, 'hospice')">View Specialists</button>
        <div class="doctors-list" id="hospice-doctors" style="display:none;">
            <!-- Dr. Hira Saeed -->
            <div class="doctor">
                <h4>Dr. Hira Saeed</h4>
                <div class="rating">
                    <i class="fas fa-star"></i><i class="fas fa-star"></i>
                    <i class="fas fa-star"></i><i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(55 reviews)</span>
                </div>
                <div class="action-buttons">
                    <button class="view-btn" onclick="toggleDetails(this)">View Details</button>
                    <button class="book-btn" onclick="openForm('Dr. Hira Saeed')">Book Now</button>
                </div>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> Serenity Care Center, Lahore</p>
                    <p><i class="fas fa-phone"></i> 0309-4455667</p>
                    <p><i class="fas fa-clock"></i> Mon-Fri: 9AM - 5PM</p>
                </div>
            </div>

            <!-- Dr. Faisal Raza -->
            <div class="doctor">
                <h4>Dr. Faisal Raza</h4>
                <div class="rating">
                    <i class="fas fa-star"></i><i class="fas fa-star"></i>
                    <i class="fas fa-star"></i><i class="fas fa-star"></i>
                    <i class="fas fa-star-half-alt"></i>
                    <span>(48 reviews)</span>
                </div>
                <div class="action-buttons">
                    <button class="view-btn" onclick="toggleDetails(this)">View Details</button>
                    <button class="book-btn" onclick="openForm('Dr. Faisal Raza')">Book Now</button>
                </div>
                <div class="doctor-info">
                    <p><i class="fas fa-hospital"></i> Comfort Life Hospice, Islamabad</p>
                    <p><i class="fas fa-phone"></i> 0317-6677889</p>
                    <p><i class="fas fa-clock"></i> Tue-Sat: 10AM - 4PM</p>
                </div>
            </div>
        </div>
    </div>
</div>


            <!-- Add more specializations following the same pattern -->
        </div>
    </div>
<!-- Review Button (place this somewhere visible, like near the search bar) -->
<div class="review-button-container" style="text-align: center; margin: 20px 0;">
    <button class="review-btn" onclick="openReviewModal()" style="background-color: var(--secondary); color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer;">
        <i class="fas fa-star"></i> Review a Doctor
    </button>
</div>

<!-- Doctor Selection Modal -->
<div class="modal" id="doctorSelectionModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Select Doctor to Review</h3>
            <span class="close" onclick="closeDoctorSelectionModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label for="review_doctor">Select Doctor:</label>
                <select id="review_doctor" class="form-control" required>
                    <option value="">-- Select a Doctor --</option>
                    <?php foreach ($doctors as $doctor): ?>
                        <option value="<?= htmlspecialchars($doctor) ?>"><?= htmlspecialchars($doctor) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="submit-btn" onclick="proceedToReview()">Proceed to Review</button>
        </div>
    </div>
</div>

<!-- Review Form Modal -->
<div class="modal" id="reviewFormModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Review Doctor</h3>
            <p id="reviewingDoctor"></p>
            <span class="close" onclick="closeReviewFormModal()">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="review_doctor" id="selected_review_doctor">
                
                <div class="form-group">
                    <label for="review_patient_name">Your Name</label>
                    <input type="text" id="review_patient_name" name="review_patient_name" class="form-control" placeholder="Enter your name" required>
                </div>
                
                <div class="form-group">
                    <label>Rating</label>
                    <div class="star-rating">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="far fa-star" data-rating="<?= $i ?>" onclick="setRating(<?= $i ?>)"></i>
                        <?php endfor; ?>
                        <input type="hidden" name="rating" id="rating" value="0" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="review_text">Your Review</label>
                    <textarea id="review_text" name="review_text" class="form-control" rows="4" placeholder="Share your experience..."></textarea>
                </div>
                
                <button type="submit" name="submit_review" class="submit-btn">Submit Review</button>
            </form>
            <form method="POST" class="cancel-form" style="display:inline;">
    <input type="hidden" name="appointment_id" value="<?= $appt['id'] ?>">
    <button type="submit" name="cancel_appointment" class="cancel-btn">
        <i class="fas fa-times"></i> Cancel
    </button>
</form>
            <?php if (isset($_SESSION['review_error'])): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($_SESSION['review_error']) ?>
                    <?php unset($_SESSION['review_error']); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<div class="data-section">
    <h2><i class="fas fa-star"></i> Doctor Ratings</h2>
    <div class="cards-container">
        <?php foreach ($doctor_ratings as $doctor): ?>
        <div class="rating-card">
            <h3><?= htmlspecialchars($doctor['name']) ?></h3>
            <p class="specialty"><?= htmlspecialchars($doctor['specialization']) ?></p>
            
            <div class="rating-display">
                <div class="stars">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="fas fa-star <?= $i <= round($doctor['avg_rating']) ? 'active' : '' ?>"></i>
                    <?php endfor; ?>
                </div>
                <span class="avg-rating"><?= number_format($doctor['avg_rating'], 1) ?></span>
                <span class="review-count">(<?= $doctor['review_count'] ?> reviews)</span>
            </div>
            
        </div>
        <?php endforeach; ?>
    </div>
</div>
<div class="dashboard-section">
    <h2><i class="fas fa-tachometer-alt"></i> Practice Dashboard</h2>
    
    <div class="stats-cards">
        <div class="stat-card">
            <div class="stat-value"><?= $stats['total_appointments'] ?></div>
            <div class="stat-label">Total Appointments</div>
        </div>
        
        <?php foreach ($stats['doctor_appointments'] as $doctor): ?>
        <div class="stat-card">
            <div class="stat-value"><?= $doctor['appointment_count'] ?></div>
            <div class="stat-label"><?= $doctor['doctor_name'] ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <div class="chart-container">
        <canvas id="appointmentsChart"></canvas>
    </div>
</div>
<!-- Review Success Popup -->
<div class="success-popup" id="reviewSuccessPopup">
    <div class="popup-content">
        <div class="popup-icon">
            <i class="fas fa-check-circle"></i>
        </div>
        <h3>Review Submitted!</h3>
        <p>Thank you for your feedback.</p>
        <button class="popup-close-btn" onclick="closeReviewSuccessPopup()">Done</button>
    </div>
</div>
<!-- Add this modal near your other modals -->
<!-- Appointment Booking Modal -->
<div class="modal" id="appointmentModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="selectedDoctor">Book Appointment</h3>
            <span class="close" onclick="closeForm()">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" id="doctorName" name="doctorName">
                
                <div class="form-group">
                    <label for="name">Your Name</label>
                    <input type="text" id="name" name="name" class="form-control" 
                           value="<?= htmlspecialchars($formData['name'] ?? '') ?>" required>
                </div>
                
                <div class="form-group">
    <label for="phone">Phone Number</label>
    <input type="tel" id="phone" name="phone" class="form-control" 
           value="<?= htmlspecialchars($formData['phone'] ?? '') ?>" 
           pattern="[0-9]{11}" 
           title="Please enter a valid 11-digit phone number (e.g., 03001234567)"
           required>
</div>
                
                <div class="form-group">
                    <label for="appointmentTime">Appointment Time</label>
                    <select id="appointmentTime" name="appointmentTime" class="form-control" required>
                        <option value="">Select a time slot</option>
                        <option value="9:00 AM">9:00 AM - 10:00 AM</option>
                        <option value="10:00 AM">10:00 AM - 11:00 AM</option>
                        <option value="11:00 AM">11:00 AM - 12:00 PM</option>
                        <option value="1:00 PM">1:00 PM - 2:00 PM</option>
                        <option value="2:00 PM">2:00 PM - 3:00 PM</option>
                        <option value="3:00 PM">3:00 PM - 4:00 PM</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="diseases">Medical Conditions (Optional)</label>
                    <textarea id="diseases" name="diseases" class="form-control" rows="3"><?= htmlspecialchars($formData['diseases'] ?? '') ?></textarea>
                </div>
                
                <button type="submit" class="submit-btn">Book Appointment</button>
            </form>
            
            <?php if (!empty($errorMessage)): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($errorMessage) ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
    <!-- Edit Appointment Modal -->
<div class="modal" id="editAppointmentModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edit Appointment</h3>
            <span class="close" onclick="closeEditModal()">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" name="appointment_id" id="edit_appointment_id">
                
                <div class="form-group">
                    <label for="new_time">New Appointment Time</label>
                    <select id="new_time" name="new_time" class="form-control" required>
                        <option value="">Select a new time slot</option>
                        <?php
                        $timeSlots = [
                            '9:00 AM' => '9:00 AM - 10:00 AM',
                            '10:00 AM' => '10:00 AM - 11:00 AM',
                            '11:00 AM' => '11:00 AM - 12:00 PM',
                            '1:00 PM' => '1:00 PM - 2:00 PM',
                            '2:00 PM' => '2:00 PM - 3:00 PM',
                            '3:00 PM' => '3:00 PM - 4:00 PM'
                        ];

                        foreach ($timeSlots as $value => $label) {
                            echo "<option value=\"$value\">$label</option>";
                        }
                        ?>
                    </select>
                </div>
                
                <button type="submit" name="update_appointment" class="submit-btn">Update Appointment</button>
            </form>
            <?php if (!empty($errorMessage)): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($errorMessage) ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
    <div class="data-section">
    <h2><i class="fas fa-calendar-alt"></i> Recent Appointments</h2>
    <div class="table-container">
        <table id="appointmentsTable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Date/Time</th>
                    <th>Doctor</th>
                    <th>Patient</th>
                    <th>Phone</th>
                    <th>Diseases</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($appointments as $appt): ?>
                <tr>
                    <td><?= htmlspecialchars($appt['id']) ?></td>
                    <td><?= htmlspecialchars($appt['time']) ?></td>
                    <td><?= htmlspecialchars($appt['doctor_name']) ?></td>
                    <td><?= htmlspecialchars($appt['patient_name']) ?></td>
                    <td><?= htmlspecialchars($appt['phone']) ?></td>
                    <td><?= htmlspecialchars($appt['diseases']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>  
<div class="data-section">
    <h2><i class="fas fa-calendar-alt"></i> Recent Appointments</h2>
    <div class="table-container">
        <table id="appointmentsTable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Date/Time</th>
                    <th>Doctor</th>
                    <th>Patient</th>
                    <th>Phone</th>
                    <th>Diseases</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($appointments as $appt): ?>
                <tr>
                    <td><?= htmlspecialchars($appt['id']) ?></td>
                    <td><?= htmlspecialchars($appt['time']) ?></td>
                    <td><?= htmlspecialchars($appt['doctor_name']) ?></td>
                    <td><?= htmlspecialchars($appt['patient_name']) ?></td>
                    <td><?= htmlspecialchars($appt['phone']) ?></td>
                    <td><?= htmlspecialchars($appt['diseases']) ?></td>
                    <td>
                       <!-- In your table row -->
<td>
    <button class="edit-btn" onclick="openEditAppointmentModal(<?= $appt['id'] ?>, '<?= htmlspecialchars($appt['time']) ?>')">
        <i class="fas fa-edit"></i> Edit
    </button>
    
    <button class="delete-btn" onclick="confirmDelete(<?= $appt['id'] ?>)">
        <i class="fas fa-trash"></i> Delete
    </button>
    
    <button class="cancel-btn" onclick="confirmCancel(<?= $appt['id'] ?>)">
        <i class="fas fa-times"></i> Cancel
    </button>
</td>

<!-- Hidden forms for delete and cancel -->
<form id="deleteForm" method="POST" style="display:none;">
    <input type="hidden" name="appointment_id" id="delete_appointment_id">
    <input type="hidden" name="delete_appointment" value="1">
</form>

<form id="cancelForm" method="POST" style="display:none;">
    <input type="hidden" name="appointment_id" id="cancel_appointment_id">
    <input type="hidden" name="cancel_appointment" value="1">
</form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<!-- Appointment Confirmed Popup -->
<div class="success-popup" id="appointmentSuccessPopup">
    <div class="popup-content">
        <div class="popup-icon">
            <i class="fas fa-check-circle"></i>
        </div>
        <h3>Appointment Confirmed!</h3>
        <p>Your appointment has been successfully scheduled with <span id="confirmedDoctor"></span>.</p>
        <button class="popup-close-btn" onclick="closeAppointmentSuccessPopup()">Done</button>
    </div>
</div>

<!-- Appointment Updated Popup -->
<div class="success-popup" id="updateSuccessPopup">
    <div class="popup-content">
        <div class="popup-icon">
            <i class="fas fa-check-circle"></i>
        </div>
        <h3>Appointment Updated!</h3>
        <p>Your appointment has been successfully rescheduled.</p>
        <button class="popup-close-btn" onclick="closeUpdateSuccessPopup()">Done</button>
    </div>
</div>

<!-- Appointment Deleted Popup -->
<div class="success-popup" id="deleteSuccessPopup">
    <div class="popup-content">
        <div class="popup-icon">
            <i class="fas fa-check-circle"></i>
        </div>
        <h3>Appointment Deleted!</h3>
        <p>Your appointment has been successfully deleted.</p>
        <button class="popup-close-btn" onclick="closeDeleteSuccessPopup()">Done</button>
    </div>
</div>
    <!-- Success Popup -->
    <div class="success-popup" id="successPopup">
        <div class="popup-content">
            <div class="popup-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h3>Appointment Confirmed!</h3>
            <p>Your appointment has been successfully scheduled with <span id="confirmedDoctor"></span>.</p>
            <p>You'll receive a confirmation SMS shortly.</p>
            <button class="popup-close-btn" onclick="closePopup()">Done</button>
        </div>
    </div>

    <script>
        // Toggle doctors list
        function toggleDoctors(button, specialization) {
            const card = button.closest('.card');
            const doctorsList = document.getElementById(`${specialization}-doctors`);
            const isExpanded = doctorsList.style.display === 'block';

            if (isExpanded) {
                doctorsList.style.display = 'none';
                button.textContent = `View ${specialization.charAt(0).toUpperCase() + specialization.slice(1)}s`;
            } else {
                doctorsList.style.display = 'block';
                button.textContent = 'Hide Doctors';
            }

            // Smooth scroll to show all doctors
            if (!isExpanded) {
                setTimeout(() => {
                    card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }, 100);
            }
        }

        // Toggle individual doctor details
        function toggleDetails(button) {
            const doctorCard = button.closest('.doctor');
            const details = doctorCard.querySelector('.doctor-info');
            const isExpanded = doctorCard.classList.contains('expanded');

            if (isExpanded) {
                doctorCard.classList.remove('expanded');
                button.textContent = 'View Details';
                details.style.maxHeight = '0';
            } else {
                doctorCard.classList.add('expanded');
                button.textContent = 'Hide Details';
                details.style.maxHeight = details.scrollHeight + 'px';

                // Smooth scroll to show the expanded details
                setTimeout(() => {
                    doctorCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }, 100);
            }
        }

        // Open Appointment Form
        function openForm(doctorName) {
            const modal = document.getElementById('appointmentModal');
            modal.style.display = 'flex';
            document.getElementById('doctorName').value = doctorName;
            document.getElementById('selectedDoctor').textContent = `Booking with ${doctorName}`;

            // Clear previous errors
            document.querySelector('.error-message')?.remove();
        }

        // Close Modal
        function closeForm() {
            document.getElementById('appointmentModal').style.display = 'none';
        }

        // Close Popup
        function closePopup() {
            document.getElementById('successPopup').style.display = 'none';
        }

        // Close modals when clicking outside
        window.onclick = function (event) {
            if (event.target === document.getElementById('appointmentModal')) {
                closeForm();
            }
            if (event.target === document.getElementById('successPopup')) {
                closePopup();
            }
        };

        // Search functionality
        document.getElementById('searchButton').addEventListener('click', performSearch);
        document.getElementById('searchInput').addEventListener('keyup', function (e) {
            if (e.key === 'Enter') {
                performSearch();
            }
        });
// Review functions
function openReviewModal() {
    document.getElementById('doctorSelectionModal').style.display = 'flex';
}

function closeDoctorSelectionModal() {
    document.getElementById('doctorSelectionModal').style.display = 'none';
}

function proceedToReview() {
    const doctorSelect = document.getElementById('review_doctor');
    if (doctorSelect.value === '') {
        alert('Please select a doctor');
        return;    
    }
    
    document.getElementById('selected_review_doctor').value = doctorSelect.value;
    document.getElementById('reviewingDoctor').textContent = `Reviewing: ${doctorSelect.value}`;
    
    closeDoctorSelectionModal();
    document.getElementById('reviewFormModal').style.display = 'flex';
}

function closeReviewFormModal() {
    document.getElementById('reviewFormModal').style.display = 'none';
    // Reset form
    document.getElementById('rating').value = '0';
    document.getElementById('review_patient_name').value = '';
    document.getElementById('review_text').value = '';
    // Reset stars
    document.querySelectorAll('.star-rating i').forEach(star => {
        star.classList.remove('fas', 'active');
        star.classList.add('far');
    });
}
// Add this to your existing JavaScript
document.addEventListener('DOMContentLoaded', function() {
    // Handle cancel forms
    document.querySelectorAll('.cancel-form').forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!confirm('Are you sure you want to cancel this appointment?')) {
                e.preventDefault();
            }
        });
    });
});
// Show appropriate popups based on session
document.addEventListener('DOMContentLoaded', function() {
    <?php if (isset($_SESSION['appointment_success']) && $_SESSION['appointment_success']): ?>
        setTimeout(function() {
            document.getElementById('appointmentSuccessPopup').style.display = 'flex';
            document.getElementById('confirmedDoctor').textContent = '<?= htmlspecialchars($bookedDoctor) ?>';
            <?php unset($_SESSION['appointment_success']); ?>
        }, 300);
    <?php endif; ?>
    
    <?php if (isset($_SESSION['update_success'])): ?>
        setTimeout(function() {
            document.getElementById('updateSuccessPopup').style.display = 'flex';
            <?php unset($_SESSION['update_success']); ?>
        }, 300);
    <?php endif; ?>
    
    <?php if (isset($_SESSION['delete_success'])): ?>
        setTimeout(function() {
            document.getElementById('deleteSuccessPopup').style.display = 'flex';
            <?php unset($_SESSION['delete_success']); ?>
        }, 300);
    <?php endif; ?>
});
function closeReviewSuccessPopup() {
    document.getElementById('reviewSuccessPopup').style.display = 'none';
}

function setRating(rating) {
    document.getElementById('rating').value = rating;
    const stars = document.querySelectorAll('.star-rating i');
    
    stars.forEach((star, index) => {
        if (index < rating) {
            star.classList.remove('far');
            star.classList.add('fas', 'active');
        } else {
            star.classList.remove('fas', 'active');
            star.classList.add('far');
        }
    });
}
// Edit appointment
function openEditAppointmentModal(appointmentId, currentTime) {
    const modal = document.getElementById('editAppointmentModal');
    document.getElementById('edit_appointment_id').value = appointmentId;
    
    // Set current time in dropdown
    const timeSelect = document.getElementById('new_time');
    for (let i = 0; i < timeSelect.options.length; i++) {
        if (timeSelect.options[i].value === currentTime) {
            timeSelect.selectedIndex = i;
            break;
        }
    }
    
    modal.style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('editAppointmentModal').style.display = 'none';
}

// Delete confirmation
function confirmDelete(appointmentId) {
    if (confirm('Are you sure you want to permanently delete this appointment?')) {
        document.getElementById('delete_appointment_id').value = appointmentId;
        document.getElementById('deleteForm').submit();
    }
}

// Cancel confirmation
function confirmCancel(appointmentId) {
    if (confirm('Are you sure you want to cancel this appointment?')) {
        document.getElementById('cancel_appointment_id').value = appointmentId;
        document.getElementById('cancelForm').submit();
    }
}


// Show review success popup if there was a successful submission
<?php if (isset($_SESSION['review_success'])): ?>
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
        document.getElementById('reviewSuccessPopup').style.display = 'flex';
        <?php unset($_SESSION['review_success']); ?>
    }, 300);
});
<?php endif; ?>
        function performSearch() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const cards = document.querySelectorAll('.card');

            cards.forEach(card => {
                const cardText = card.textContent.toLowerCase();
                if (cardText.includes(searchTerm)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }
// Add this to your existing JavaScript
document.addEventListener('DOMContentLoaded', function() {
    // Handle cancel forms
    document.querySelectorAll('.cancel-form').forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!confirm('Are you sure you want to cancel this appointment?')) {
                e.preventDefault();
            } else {
                // Set a flag to indicate this is a cancellation
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'is_cancellation';
                hiddenInput.value = '1';
                this.appendChild(hiddenInput);
            }
        });
    });
});
// Show appropriate popups based on session
document.addEventListener('DOMContentLoaded', function() {
    <?php if (isset($_SESSION['update_success'])): ?>
        setTimeout(function() {
            document.getElementById('updateSuccessPopup').style.display = 'flex';
            <?php unset($_SESSION['update_success']); ?>
        }, 300);
    <?php endif; ?>
    
    <?php if (isset($_SESSION['delete_success'])): ?>
        setTimeout(function() {
            document.getElementById('deleteSuccessPopup').style.display = 'flex';
            <?php unset($_SESSION['delete_success']); ?>
        }, 300);
    <?php endif; ?>
    
    <?php if (isset($_SESSION['cancel_success'])): ?>
        setTimeout(function() {
            document.getElementById('cancelSuccessPopup').style.display = 'flex';
            <?php unset($_SESSION['cancel_success']); ?>
        }, 300);
    <?php endif; ?>
});
// Add this to your JavaScript section
document.querySelector('form').addEventListener('submit', function(e) {
    const phoneInput = document.getElementById('phone');
    const phoneRegex = /^[0-9]{11}$/;
    
    if (!phoneRegex.test(phoneInput.value)) {
        alert('Please enter a valid 11-digit phone number (e.g., 03001234567)');
        e.preventDefault();
        phoneInput.focus();
        return false;
    }
    return true;
});
        // Show success popup if there was a successful submission
<?php if ($showSuccessPopup): ?>
document.addEventListener('DOMContentLoaded', function() {
    // Small delay to ensure all elements are loaded
    setTimeout(function() {
        const popup = document.getElementById('successPopup');
        if (popup) {
            popup.style.display = 'flex';
            document.getElementById('confirmedDoctor').textContent = '<?= htmlspecialchars($bookedDoctor) ?>';
            
            // Auto-close after 5 seconds
            setTimeout(function() {
                popup.style.display = 'none';
            }, 5000);
        }
    }, 300);
});
<?php endif; ?>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('appointmentsChart').getContext('2d');
    
    // Prepare data from PHP
    const doctorData = <?php echo json_encode($stats['doctor_appointments']); ?>;
    
    const labels = doctorData.map(d => d.doctor_name);
    const data = doctorData.map(d => d.appointment_count);
    
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Appointments',
                data: data,
                backgroundColor: '#3a86ff',
                borderColor: '#2667cc',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
});
function closeUpdateSuccessPopup() {
    document.getElementById('updateSuccessPopup').style.display = 'none';
}

function closeDeleteSuccessPopup() {
    document.getElementById('deleteSuccessPopup').style.display = 'none';
}

function closeCancelSuccessPopup() {
    document.getElementById('cancelSuccessPopup').style.display = 'none';
}

// Update the window.onclick handler
window.onclick = function(event) {
    if (event.target === document.getElementById('appointmentModal')) {
        closeForm();
    }
    if (event.target === document.getElementById('appointmentSuccessPopup')) {
        closeAppointmentSuccessPopup();
    }
    if (event.target === document.getElementById('updateSuccessPopup')) {
        closeUpdateSuccessPopup();
    }
    if (event.target === document.getElementById('deleteSuccessPopup')) {
        closeDeleteSuccessPopup();
    }
    if (event.target === document.getElementById('editAppointmentModal')) {
        closeEditModal();
    }
};


// Show error message if there was an error
<?php if (!empty($errorMessage)): ?>
document.addEventListener('DOMContentLoaded', function() {
    // You can show this in your modal or as an alert
    alert('<?= htmlspecialchars($errorMessage) ?>');
    
    // Or to show in your form's error container:
    // document.getElementById('errorContainer').textContent = '<?= htmlspecialchars($errorMessage) ?>';
    // document.getElementById('appointmentModal').style.display = 'flex';
});
<?php endif; ?>
    </script>
</body>

</html>

<?php
?>