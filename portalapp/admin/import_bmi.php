<?php
session_start();
require_once 'db.php'; // ไฟล์สำหรับเชื่อมต่อฐานข้อมูล

// ==========================================
// 1. รับข้อมูลจาก AJAX เพื่อนำไปบันทึกลงฐานข้อมูล
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {$json_data = file_get_contents('php://input');
    $data = json_decode($json_data, true);
    
    if (isset($data['action']) &&$data['action'] === 'import_bmi') {
        header('Content-Type: application/json; charset=utf-8');
        
        if (empty($data['students'])) {
            echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลนักเรียน กรุณาตรวจสอบไฟล์อีกครั้ง']);
            exit;
        }

        $academic_term = trim($data['term'] ?? 'ไม่ระบุ');
        $success_count = 0;
        $error_count = 0;
        
        // เตรียมคำสั่ง SQL สำหรับเพิ่มข้อมูลใหม่ลงในตาราง student_bmi_records
        $stmt =$conn->prepare("INSERT INTO student_bmi_records (student_code, academic_term, age, weight, height, bmi, bmi_category) VALUES (?, ?, ?, ?, ?, ?, ?)");
        
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล: ' . $conn->error]);
            exit;
        }
        
        foreach ($data['students'] as$row) {
            $student_code =$row['student_code'] ?? '';
            $age = isset($row['age']) && $row['age'] !== '' ? (int)$row['age'] : null;
            $weight = isset($row['weight']) && $row['weight'] !== '' ? (float)$row['weight'] : null;
            $height = isset($row['height']) && $row['height'] !== '' ? (float)$row['height'] : null;
            $bmi = isset($row['bmi']) && $row['bmi'] !== '' ? (float)$row['bmi'] : null;
            $bmi_category =$row['bmi_category'] ?? null;
            
            if (!empty($student_code)) {$stmt->bind_param("ssiddds", $student_code,$academic_term, $age,$weight, $height,$bmi, $bmi_category);$stmt->execute();
                
                if ($stmt->errno === 0) {$success_count++;
                } else {
                    $error_count++;
                }
            }
        }
        $stmt->close();
        
        echo json_encode([
            'status' => 'success',
            'message' => "บันทึกประวัติสำเร็จ จำนวน $success_count รายการ",
            'errors' => $error_count
        ]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>อัปโหลดประวัติ BMI - MTTS</title>
    
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- SheetJS และ SweetAlert2 -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root {
            --primary: #503896;
            --secondary: #6b4c9a;
            --bg: #f3f4f6;
            --text-dark: #1f2937;
        }
        * { box-sizing: border-box; font-family: 'Prompt', sans-serif; }
        body { background-color: var(--bg); margin: 0; padding: 20px; display: flex; justify-content: center; min-height: 100vh; align-items: center; }
        
        .upload-card {
            background: #fff;
            width: 100%;
            max-width: 600px;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            margin: auto;
            text-align: center;
        }
        .upload-card h2 { color: var(--primary); margin-top: 0; }
        .upload-card p { color: #6b7280; font-size: 0.95rem; margin-bottom: 25px; }
        
        .form-group {
            text-align: left;
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 8px;
            color: var(--text-dark);
        }
        .form-group input[type="text"] {
            width: 100%;
            padding: 12px 15px;
            border: 1.5px solid #d1d5db;
            border-radius: 8px;
            font-size: 1rem;
            outline: none;
            transition: border-color 0.3s;
        }
        .form-group input[type="text"]:focus {
            border-color: var(--primary);
        }

        .drop-zone {
            border: 2.5px dashed #d1d5db;
            border-radius: 12px;
            padding: 40px 20px;
            background: #f9fafb;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }
        .drop-zone:hover, .drop-zone.dragover {
            border-color: var(--primary);
            background: #f5f3ff;
        }
        .drop-zone i { font-size: 3.5rem; color: #9ca3af; margin-bottom: 15px; transition: color 0.3s ease; }
        .drop-zone:hover i { color: var(--primary); }
        .drop-zone span { display: block; font-size: 1.1rem; color: #4b5563; font-weight: 500; }
        .drop-zone small { color: #9ca3af; font-size: 0.85rem; }
        
        input[type="file"] {
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            opacity: 0;
            cursor: pointer;
        }

        .file-info {
            margin-top: 20px;
            display: none;
            padding: 15px;
            background: #eef2ff;
            border-radius: 8px;
            color: var(--primary);
            font-weight: 500;
            text-align: left;
        }

        .btn-upload {
            background-color: var(--primary);
            color: #fff;
            border: none;
            padding: 14px 30px;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 500;
            cursor: pointer;
            margin-top: 25px;
            width: 100%;
            transition: background 0.3s;
            display: none;
        }
        .btn-upload:hover { background-color: var(--secondary); }
        
        #loading { display: none; margin-top: 20px; color: var(--primary); }
    </style>
</head>
<body>

    <div class="upload-card">
        <h2><i class="fas fa-file-excel"></i> บันทึกประวัติ BMI</h2>
        <p>นำเข้าข้อมูลเพื่อเก็บประวัติน้ำหนักและส่วนสูงรายเทอม</p>

        <div class="form-group">
            <label for="academic_term"><i class="fas fa-calendar-alt"></i> ภาคเรียน/ปีการศึกษา</label>
            <input type="text" id="academic_term" placeholder="ตัวอย่าง: ภาคต้น/2569" value="ภาคต้น/2569">
        </div>

        <div class="drop-zone" id="dropZone">
            <i class="fas fa-cloud-upload-alt"></i>
            <span>คลิกหรือลากไฟล์ Excel มาวางที่นี่</span>
            <small>รองรับไฟล์นามสกุล .xlsx, .xls</small>
            <input type="file" id="excel_file" accept=".xlsx, .xls, .csv">
        </div>

        <div class="file-info" id="fileInfo">
            <i class="fas fa-check-circle"></i> <b>เลือกไฟล์:</b> <span id="fileName"></span><br>
            <i class="fas fa-users" style="margin-top:8px;"></i> <b>จำนวนที่พบ:</b> <span id="recordCount">0</span> รายการ
        </div>

        <button class="btn-upload" id="uploadBtn">
            <i class="fas fa-save"></i> บันทึกข้อมูลเข้าระบบ
        </button>
        
        <div id="loading">
            <i class="fas fa-spinner fa-spin fa-2x"></i>
            <p style="margin-top: 10px;">กำลังบันทึกข้อมูล โปรดรอสักครู่...</p>
        </div>
    </div>

    <script>
        let excelData = [];
        
        const fileInput = document.getElementById('excel_file');
        const dropZone = document.getElementById('dropZone');
        const fileInfo = document.getElementById('fileInfo');
        const fileNameDisplay = document.getElementById('fileName');
        const recordCountDisplay = document.getElementById('recordCount');
        const uploadBtn = document.getElementById('uploadBtn');
        const loading = document.getElementById('loading');
        const termInput = document.getElementById('academic_term');

        // แสดงสไตล์เมื่อลากไฟล์
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });
        dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
        dropZone.addEventListener('drop', () => dropZone.classList.remove('dragover'));

        // เมื่อเลือกไฟล์
        fileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;

            fileNameDisplay.textContent = file.name;
            
            const reader = new FileReader();
            reader.onload = function(e) {
                const data = new Uint8Array(e.target.result);
                const workbook = XLSX.read(data, {type: 'array'});
                
                const firstSheetName = workbook.SheetNames[0];
                const worksheet = workbook.Sheets[firstSheetName];
                const json = XLSX.utils.sheet_to_json(worksheet, {header: 1});
                
                excelData = [];
                // เริ่มที่บรรทัดที่ 2 (ข้าม Header)
                for (let i = 2; i < json.length; i++) {
                    const row = json[i];
                    if (row && row[0]) {
                        excelData.push({
                            student_code: String(row[0]).trim(),
                            age: row[3] ? row[3] : null,
                            weight: row[4] ? row[4] : null,
                            height: row[5] ? row[5] : null,
                            bmi: row[6] ? row[6] : null,
                            bmi_category: row[7] ? String(row[7]).trim() : null
                        });
                    }
                }

                recordCountDisplay.textContent = excelData.length;
                fileInfo.style.display = 'block';
                
                if(excelData.length > 0) {
                    uploadBtn.style.display = 'block';
                } else {
                    uploadBtn.style.display = 'none';
                    Swal.fire('คำเตือน', 'ไม่พบข้อมูลนักเรียนในไฟล์นี้', 'warning');
                }
            };
            reader.readAsArrayBuffer(file);
        });

        // เมื่อกดปุ่มบันทึก
        uploadBtn.addEventListener('click', function() {
            const termValue = termInput.value.trim();
            if (termValue === '') {
                Swal.fire('ข้อผิดพลาด', 'กรุณาระบุ ภาคเรียน/ปีการศึกษา', 'error');
                termInput.focus();
                return;
            }

            Swal.fire({
                title: 'ยืนยันการบันทึก?',
                text: `บันทึกข้อมูล ${excelData.length} รายการ สำหรับ ${termValue}`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#503896',
                cancelButtonColor: '#d33',
                confirmButtonText: 'ใช่, บันทึกเลย',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    
                    uploadBtn.style.display = 'none';
                    loading.style.display = 'block';

                    fetch('', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'import_bmi',
                            term: termValue,
                            students: excelData
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        loading.style.display = 'none';
                        uploadBtn.style.display = 'block';

                        if (data.status === 'success') {
                            Swal.fire('สำเร็จ!', data.message, 'success').then(() => {
                                fileInput.value = '';
                                fileInfo.style.display = 'none';
                                uploadBtn.style.display = 'none';
                                excelData = [];
                            });
                        } else {
                            Swal.fire('ล้มเหลว', data.message, 'error');
                        }
                    })
                    .catch(error => {
                        loading.style.display = 'none';
                        uploadBtn.style.display = 'block';
                        Swal.fire('ข้อผิดพลาดระบบ', 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'error');
                    });
                }
            });
        });
    </script>
</body>
</html>
