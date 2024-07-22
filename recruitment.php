<?php
/**
 * Class Recruitment
 * Обрабатывает распределение кандидатов и создание отчетов
 */
class Recruitment {
    private PDO $conn;
    private ?string $lastCorrectDate;

    /**
     * Recruitment constructor.
     * @param PDO $db
     */
    public function __construct(PDO $db) {
        $this->conn = $db;
        $this->lastCorrectDate = $this->getLastCorrectDate();
    }

    /**
     * Получает последнюю корректную дату до сбоя в CRM
     * @return string|null
     */
    private function getLastCorrectDate(): ?string {
        $query = "
            SELECT MAX(cea.created_at) AS last_correct_date
            FROM candidate_to_employee_assign cea
            JOIN employees e ON cea.employee_id = e.id
            WHERE e.role = 'Рекрутер' AND cea.created_at IS NOT NULL;
        ";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['last_correct_date'] ?? null;
    }

    /**
     * Выводит статистику по запросам
     */
    public function getStats(): void {
        if (!$this->lastCorrectDate) {
            echo "Не удалось определить дату сбоя.\n";
            return;
        }

        $this->log("Generating statistics...");

        // Сколько кандидатов сделали тестовое задание и были закреплены за рекрутерами до сбоя в CRM
        $query = "
            SELECT COUNT(DISTINCT c.id) AS count
            FROM candidates c
            JOIN candidate_to_employee_assign cea ON c.id = cea.candidate_id AND c.city_id = cea.city_id
            JOIN employees e ON cea.employee_id = e.id
            WHERE c.date_test < :lastCorrectDate AND cea.created_at < :lastCorrectDate AND e.role = 'Рекрутер';
        ";
        $stmt = $this->conn->prepare($query);
        $stmt->execute(['lastCorrectDate' => $this->lastCorrectDate]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "Кандидатов сделали тестовое задание и были закреплены за рекрутерами до сбоя: " . $result['count'] . "\n";
        $this->log("Candidates assigned before CRM failure: " . $result['count']);
    }

    /**
     * Распределяет кандидатов среди сотрудников и создает отчеты
     */
    public function distributeCandidates(): void {
        if (!$this->lastCorrectDate) {
            echo "Не удалось определить дату сбоя. Распределение кандидатов не выполнено.\n";
            return;
        }

        $this->log("Distributing candidates...");

        $this->conn->beginTransaction();

        $query = "
            -- Шаг 1: Обновление количества прикрепленных кандидатов у сотрудников
            UPDATE employees e
            SET e.attached_candidates_count = (
                SELECT COUNT(*)
                FROM candidate_to_employee_assign ca
                WHERE ca.employee_id = e.id
            );

            -- Шаг 2: Распределение кандидатов рекрутерам
            INSERT INTO candidate_to_employee_assign (candidate_id, city_id, employee_id, created_at)
            SELECT c.id, c.city_id, e.id, NOW()
            FROM candidates c
            JOIN employees e ON e.role = 'Рекрутер'
            LEFT JOIN candidate_to_employee_assign cea ON c.id = cea.candidate_id AND c.city_id = cea.city_id
            WHERE c.date_test >= :lastCorrectDate AND cea.candidate_id IS NULL
            ORDER BY e.efficiency DESC, e.attached_candidates_count ASC
            LIMIT 3000;

            -- Шаг 3: Распределение кандидатов разработчикам
            INSERT INTO candidate_to_employee_assign (candidate_id, city_id, employee_id, created_at)
            SELECT c.id, c.city_id, e.id, NOW()
            FROM candidates c
            JOIN employees e ON e.role = 'Разработчик'
            LEFT JOIN candidate_to_employee_assign cea ON c.id = cea.candidate_id AND c.city_id = cea.city_id
            WHERE c.date_test >= :lastCorrectDate AND cea.candidate_id IS NULL
            ORDER BY e.efficiency DESC, e.attached_candidates_count ASC
            LIMIT 3000;
        ";

        $stmt = $this->conn->prepare($query);
        $stmt->execute(['lastCorrectDate' => $this->lastCorrectDate]);

        $this->conn->commit();

        $this->log("Candidates distribution completed.");
    }

    /**
     * Генерирует отчеты по рекрутерам и разработчикам
     */
    public function generateReports(): void {
        if (!$this->lastCorrectDate) {
            echo "Не удалось определить дату сбоя. Генерация отчетов не выполнена.\n";
            return;
        }

        $this->log("Generating reports...");

        // Отчет по рекрутерам
        $query = "
            SELECT e.fio, 
                   COUNT(ca1.candidate_id) AS initial_count,
                   SUM(CASE WHEN ca2.created_at >= :lastCorrectDate THEN 1 ELSE 0 END) AS final_count,
                   COUNT(DISTINCT ca2.candidate_id) AS assigned_candidates
            FROM employees e
            LEFT JOIN candidate_to_employee_assign ca1 ON e.id = ca1.employee_id AND ca1.created_at < :lastCorrectDate
            LEFT JOIN candidate_to_employee_assign ca2 ON e.id = ca2.employee_id
            WHERE e.role = 'Рекрутер'
            GROUP BY e.id
        ";
        $stmt = $this->conn->prepare($query);
        $stmt->execute(['lastCorrectDate' => $this->lastCorrectDate]);
        $recruiterReport = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Отчет по разработчикам
        $query = "
            SELECT e.fio, 
                   COUNT(ca1.candidate_id) AS initial_count,
                   SUM(CASE WHEN ca2.created_at >= :lastCorrectDate THEN 1 ELSE 0 END) AS final_count,
                   COUNT(DISTINCT ca2.candidate_id) AS assigned_candidates
            FROM employees e
            LEFT JOIN candidate_to_employee_assign ca1 ON e.id = ca1.employee_id AND ca1.created_at < :lastCorrectDate
            LEFT JOIN candidate_to_employee_assign ca2 ON e.id = ca2.employee_id
            WHERE e.role = 'Разработчик'
            GROUP BY e.id
        ";
        $stmt = $this->conn->prepare($query);
        $stmt->execute(['lastCorrectDate' => $this->lastCorrectDate]);
        $developerReport = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->createCsvReport('recruiter_report.csv', $recruiterReport);
        $this->createCsvReport('developer_report.csv', $developerReport);

        $this->log("Reports generated.");
    }

    /**
     * Находит разработчика, которому досталось больше всего новых кандидатов
     */
    public function findTopDeveloper(): void {
        if (!$this->lastCorrectDate) {
            echo "Не удалось определить дату сбоя. Не удалось определить топового разработчика.\n";
            return;
        }

        $this->log("Finding top developer...");

        $query = "
            SELECT e.fio, COUNT(ca.candidate_id) AS assigned_candidates
            FROM employees e
            JOIN candidate_to_employee_assign ca ON e.id = ca.employee_id
            WHERE e.role = 'Разработчик' AND ca.created_at >= :lastCorrectDate
            GROUP BY e.id
            ORDER BY assigned_candidates DESC
            LIMIT 1
        ";
        $stmt = $this->conn->prepare($query);
        $stmt->execute(['lastCorrectDate' => $this->lastCorrectDate]);
        $topDeveloper = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($topDeveloper) {
            echo "Разработчик с наибольшим количеством новых кандидатов: " . $topDeveloper['fio'] . ", Количество: " . $topDeveloper['assigned_candidates'] . "\n";
            $this->log("Top developer: " . $topDeveloper['fio'] . " with " . $topDeveloper['assigned_candidates'] . " candidates.");
        } else {
            echo "Нет новых кандидатов для разработчиков.\n";
            $this->log("No new candidates for developers.");
        }
    }

    /**
     * Создает отчет в формате CSV
     *
     * @param string $filename
     * @param array $data
     */
    private function createCsvReport(string $filename, array $data): void {
        if (empty($data)) {
            $this->log("No data to write to {$filename}");
            return;
        }

        $file = fopen($filename, 'w');
        fputcsv($file, array_keys($data[0]));
        foreach ($data as $row) {
            fputcsv($file, $row);
        }
        fclose($file);
        $this->log("Report {$filename} created.");
    }

    /**
     * Логирует сообщение в файл
     *
     * @param string $message
     */
    private function log(string $message): void {
        error_log($message . "\n", 3, 'recruitment.log');
    }
}

?>
