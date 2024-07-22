<?php
set_time_limit(0);
require_once 'database.php';
require_once 'recruitment.php';

$database = new Database();
$db = $database->getConnection();
$recruitment = new Recruitment($db);

$recruitment->getStats(); // Сколько Кандидатов сделали тестовое задание и при этом были закреплены за рекрутерами до сбоя в CRM?
$recruitment->distributeCandidates();
$recruitment->generateReports();
$recruitment->findTopDeveloper(); // Какому разработчику после распределения досталось больше всего новых кандидатов и их тестовых заданий?
?>