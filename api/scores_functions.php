<?php
// includes/scores_functions.php
// Kumpulan fungsi untuk manajemen nilai ujian dan ijazah

if (!function_exists('getCurrentAcademicYear')) {
    function getCurrentAcademicYear() {
        $year = date('Y');
        return $year . '/' . ($year + 1);
    }
}

if (!function_exists('safeArray')) {
    function safeArray($data) {
        return is_array($data) ? $data : [];
    }
}

// --- Data Master ---
if (!function_exists('getExamTypes')) {
    function getExamTypes() {
        $result = supabase_admin_request('GET', 'exam_types', null, ['order' => 'id.asc']);
        return safeArray($result);
    }
}

if (!function_exists('getAllClasses')) {
    function getAllClasses() {
        $result = supabase_admin_request('GET', 'classes', null, ['order' => 'class_type.asc, class_name.asc']);
        return safeArray($result);
    }
}

// --- Kelas yang diajar guru (berdasarkan schedules) ---
if (!function_exists('getTeacherClasses')) {
    function getTeacherClasses($teacher_id, $academic_year, $semester) {
        $params = [
            'select' => 'class_id,classes(id,class_name,class_type,grade_level)',
            'teacher_id' => 'eq.' . $teacher_id,
            'academic_year' => 'eq.' . $academic_year,
            'semester' => 'eq.' . $semester
        ];
        $result = supabase_admin_request('GET', 'schedules', null, $params);
        $result = safeArray($result);
        $classes = [];
        foreach ($result as $item) {
            if (isset($item['classes'])) {
                $cid = $item['classes']['id'];
                if (!isset($classes[$cid])) {
                    $classes[$cid] = [
                        'id' => $cid,
                        'class_name' => $item['classes']['class_name'],
                        'class_type' => $item['classes']['class_type'] ?? 'pagi',
                        'grade_level' => $item['classes']['grade_level'] ?? 1
                    ];
                }
            }
        }
        return array_values($classes);
    }
}

// --- Siswa dalam kelas (berdasarkan kelas_pagi_id atau kelas_diniyyah_id) ---
if (!function_exists('getStudentsByClass')) {
    function getStudentsByClass($class_id, $class_type = 'pagi') {
        $filter = ($class_type == 'pagi') ? 'kelas_pagi_id' : 'kelas_diniyyah_id';
        $result = supabase_admin_request('GET', 'users', null, [
            'role' => 'eq.student',
            $filter => 'eq.' . $class_id,
            'order' => 'full_name.asc'
        ]);
        return safeArray($result);
    }
}

// --- Mata pelajaran dari jadwal (schedules) untuk suatu kelas ---
if (!function_exists('getSubjectsByClass')) {
    function getSubjectsByClass($class_id, $academic_year, $semester) {
        $params = [
            'select' => 'subject_id,subjects(id,subject_name,subject_code,grade_level)',
            'class_id' => 'eq.' . $class_id,
            'academic_year' => 'eq.' . $academic_year,
            'semester' => 'eq.' . $semester
        ];
        $result = supabase_admin_request('GET', 'schedules', null, $params);
        $result = safeArray($result);
        $subjects = [];
        foreach ($result as $item) {
            if (isset($item['subjects'])) {
                $sid = $item['subjects']['id'];
                if (!isset($subjects[$sid])) {
                    $subjects[$sid] = [
                        'id' => $sid,
                        'subject_name' => $item['subjects']['subject_name'],
                        'subject_code' => $item['subjects']['subject_code'] ?? '',
                        'grade_level' => $item['subjects']['grade_level'] ?? null
                    ];
                }
            }
        }
        return array_values($subjects);
    }
}

// --- Mata pelajaran berdasarkan tingkat kelas (dari tabel subjects) ---
if (!function_exists('getSubjectsByGradeLevel')) {
    function getSubjectsByGradeLevel($grade_level) {
        $result = supabase_admin_request('GET', 'subjects', null, [
            'grade_level' => 'eq.' . $grade_level,
            'order' => 'subject_name.asc'
        ]);
        return safeArray($result);
    }
}

// --- Mata pelajaran yang diajar guru di suatu kelas (dari schedules) ---
if (!function_exists('getTeacherSubjects')) {
    function getTeacherSubjects($teacher_id, $class_id, $academic_year, $semester) {
        $params = [
            'select' => 'subject_id,subjects(id,subject_name,subject_code)',
            'teacher_id' => 'eq.' . $teacher_id,
            'class_id' => 'eq.' . $class_id,
            'academic_year' => 'eq.' . $academic_year,
            'semester' => 'eq.' . $semester
        ];
        $result = supabase_admin_request('GET', 'schedules', null, $params);
        $result = safeArray($result);
        $subjects = [];
        foreach ($result as $item) {
            if (isset($item['subjects'])) {
                $sid = $item['subjects']['id'];
                if (!isset($subjects[$sid])) {
                    $subjects[$sid] = [
                        'id' => $sid,
                        'subject_name' => $item['subjects']['subject_name'],
                        'subject_code' => $item['subjects']['subject_code'] ?? ''
                    ];
                }
            }
        }
        return array_values($subjects);
    }
}

// --- Nilai ujian ---
if (!function_exists('getScore')) {
    function getScore($student_id, $subject_id, $exam_type_id, $academic_year, $semester) {
        $result = supabase_admin_request('GET', 'exam_scores', null, [
            'student_id' => 'eq.' . $student_id,
            'subject_id' => 'eq.' . $subject_id,
            'exam_type_id' => 'eq.' . $exam_type_id,
            'academic_year' => 'eq.' . $academic_year,
            'semester' => 'eq.' . $semester,
            'limit' => 1
        ]);
        if (is_array($result) && count($result) > 0 && isset($result[0]['score'])) {
            return $result[0]['score'];
        }
        return null;
    }
}

if (!function_exists('saveScore')) {
    function saveScore($student_id, $subject_id, $exam_type_id, $academic_year, $semester, $score, $notes = '') {
        $existing = supabase_admin_request('GET', 'exam_scores', null, [
            'student_id' => 'eq.' . $student_id,
            'subject_id' => 'eq.' . $subject_id,
            'exam_type_id' => 'eq.' . $exam_type_id,
            'academic_year' => 'eq.' . $academic_year,
            'semester' => 'eq.' . $semester
        ]);
        $data = [
            'score' => $score,
            'notes' => $notes,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        if (is_array($existing) && count($existing) > 0) {
            $id = $existing[0]['id'];
            return supabase_admin_request('PATCH', 'exam_scores', $data, ['id' => 'eq.' . $id]);
        } else {
            $data['student_id'] = $student_id;
            $data['subject_id'] = $subject_id;
            $data['exam_type_id'] = $exam_type_id;
            $data['academic_year'] = $academic_year;
            $data['semester'] = $semester;
            $data['created_at'] = date('Y-m-d H:i:s');
            return supabase_admin_request('POST', 'exam_scores', $data);
        }
    }
}

// --- Ijazah / Report Card ---
if (!function_exists('getReportCard')) {
    function getReportCard($student_id, $academic_year) {
        $result = supabase_admin_request('GET', 'report_cards', null, [
            'student_id' => 'eq.' . $student_id,
            'academic_year' => 'eq.' . $academic_year,
            'limit' => 1
        ]);
        if (is_array($result) && count($result) > 0) {
            return $result[0];
        }
        return null;
    }
}

if (!function_exists('saveReportCard')) {
    function saveReportCard($student_id, $academic_year, $average_score, $status, $certificate_number, $graduation_date = null) {
        $existing = getReportCard($student_id, $academic_year);
        $data = [
            'average_score' => $average_score,
            'status' => $status,
            'certificate_number' => $certificate_number,
            'graduation_date' => $graduation_date ?: date('Y-m-d'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        if ($existing) {
            return supabase_admin_request('PATCH', 'report_cards', $data, ['id' => 'eq.' . $existing['id']]);
        } else {
            $data['student_id'] = $student_id;
            $data['academic_year'] = $academic_year;
            $data['created_at'] = date('Y-m-d H:i:s');
            return supabase_admin_request('POST', 'report_cards', $data);
        }
    }
}

if (!function_exists('getReportCardScores')) {
    function getReportCardScores($report_card_id) {
        $result = supabase_admin_request('GET', 'report_card_scores', null, [
            'report_card_id' => 'eq.' . $report_card_id
        ]);
        $result = safeArray($result);
        $scores = [];
        foreach ($result as $item) {
            if (isset($item['subject_id'])) {
                $scores[$item['subject_id']] = $item['final_score'];
            }
        }
        return $scores;
    }
}

if (!function_exists('saveReportCardScores')) {
    function saveReportCardScores($report_card_id, $subject_scores) {
        // Hapus semua nilai lama untuk report_card ini
        supabase_admin_request('DELETE', 'report_card_scores', null, ['report_card_id' => 'eq.' . $report_card_id]);
        $total = 0;
        $count = 0;
        foreach ($subject_scores as $subject_id => $score) {
            if ($score !== '' && is_numeric($score) && $score >= 0 && $score <= 100) {
                supabase_admin_request('POST', 'report_card_scores', [
                    'report_card_id' => $report_card_id,
                    'subject_id' => $subject_id,
                    'final_score' => (float)$score,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                $total += (float)$score;
                $count++;
            }
        }
        $average = ($count > 0) ? round($total / $count, 2) : 0;
        // Update average_score di report_cards
        supabase_admin_request('PATCH', 'report_cards', ['average_score' => $average], ['id' => 'eq.' . $report_card_id]);
        return $average;
    }
}
?>