<?php
//IMathAS: Course browser data maps
//(c) 2017 David Lippman

if (isset($CFG['browser']['levels'])) {
  $levels = $CFG['browser']['levels'];
} else {
  $levels = array('arith'=>'Arithmetic',
    'prealg'=>'Prealgebra',
    'elemalg'=>'Elementary Algebra',
    'intalg'=>'Intermediate Algebra',
    'mathlit'=>'Non-STEM Algebra / Math Literacy',
    'precalc'=>'College Algebra / Precalculus',
    'trig'=>'Trigonometry',
    'calc'=>'Calculus',
    'diffeq'=>'Differential Equations',
    'linalg'=>'Linear Algebra',
    'stats'=>'Statistics',
    'qr'=>'Math for Liberal Arts / Quantitative Reasonsing',
    'other'=>'Other'
  );
}

if (isset($CFG['browser']['modalities'])) {
  $modes = $CFG['browser']['modalities'];
} else {
  $modes = array(
    'generic'=>'Generic, nonspecific',
    'class'=>'Classroom instruction',
    'hybrid'=>'Hybrid',
    'online'=>'Fully online',
    'lab'=>'Emporium');
}

if (isset($CFG['browser']['contenttypes'])) {
  $contenttypes = $CFG['browser']['contenttypes'];
} else {
  $contenttypes = array('FA1'=>'Formative Assessments (homework; roughly 1 per week or chapter)',
    'FA2'=>'Formative Assessments (homework; roughly 1 per day or section)',
    'SA'=>'Summative Assessments (quizzes or exams)',
    'V'=>'Video lists or video lessons',
    'B'=>'Textbook files or links',
    'PP'=>'PowerPoint slides',
    'WS'=>'Worksheets or activities',
    'I'=>'Instructor planning resources');
}
