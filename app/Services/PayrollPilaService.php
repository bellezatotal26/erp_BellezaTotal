<?php

namespace SoftNova\Services;

/**
 * PILA operativa (Fase 2): validación de afiliaciones + export CSV/TXT
 * para cargar en el operador de información (SOI, miplanilla, etc.).
 *
 * No sustituye el archivo fijo Anexo Técnico 2 completo del operador;
 * entrega un archivo operativo con los campos esenciales tipo E.
 */
class PayrollPilaService
{
    public function __construct(private \PDO $db)
    {
        TenantOpsSchema::ensure($db);
    }

    private function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** Catálogo corto de administradoras frecuentes (código => nombre). */
    public static function catalogs(): array
    {
        return [
            'eps' => [
                'EPS001' => 'Aliansalud',
                'EPS002' => 'Salud Total',
                'EPS005' => 'Sanitas',
                'EPS008' => 'Compensar',
                'EPS010' => 'Sura',
                'EPS012' => 'Comfenalco Valle',
                'EPS017' => 'Famisanar',
                'EPS018' => 'SOS',
                'EPS037' => 'Nueva EPS',
                'EPS040' => 'Savia Salud',
                'EPS046' => 'Salud Mía',
                'EPS047' => 'Salud Bolívar',
                'ESSC07' => 'Mutual Ser',
                'ESSC18' => 'Emssanar',
                'ESSC24' => 'Coosalud',
                'ESSC62' => 'Asmet Salud',
            ],
            'afp' => [
                '230201' => 'Protección',
                '230301' => 'Porvenir',
                '230901' => 'Skandia',
                '231001' => 'Colfondos',
                '25-14' => 'Colpensiones',
            ],
            'arl' => [
                '14-11' => 'Sura',
                '14-17' => 'Alfa',
                '14-23' => 'Positiva',
                '14-25' => 'Colmena',
                '14-4' => 'Colpatria',
                '14-7' => 'Bolívar',
                '14-8' => 'Aurora',
                '14-29' => 'Equidad',
                '14-30' => 'Mapfre',
                '14-33' => 'Sanitas',
            ],
            'caja' => [
                'CCF03' => 'Comfenalco Antioquia',
                'CCF04' => 'Comfama',
                'CCF21' => 'Cafam',
                'CCF22' => 'Colsubsidio',
                'CCF24' => 'Compensar',
                'CCF56' => 'Comfenalco Valle',
                'CCF57' => 'Comfandi',
                'CCF40' => 'Comfenalco Santander',
                'CCF06' => 'Combarranquilla',
                'CCF07' => 'Comfamiliar Atlántico',
            ],
            'cotizante_type' => [
                '1' => '1 · Dependiente',
                '12' => '12 · Aprendiz etapa lectiva',
                '19' => '19 · Aprendiz etapa productiva',
                '51' => '51 · Tiempo parcial',
            ],
        ];
    }

    private function setting(string $key, string $default = ''): string
    {
        $row = $this->query(
            'SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1',
            [$key]
        )->fetchColumn();
        return $row !== false && $row !== null && (string)$row !== '' ? (string)$row : $default;
    }

    /**
     * Construye filas PILA + validaciones a partir de una liquidación.
     *
     * @return array{
     *   run:array<string,mixed>,
     *   company:array{name:string,nit:string},
     *   period:string,
     *   rows:list<array<string,mixed>>,
     *   totals:array<string,float|int>,
     *   issues:list<array{level:string,employee:string,message:string}>,
     *   ready:bool
     * }
     */
    public function buildFromRun(int $runId): array
    {
        $run = $this->query('SELECT * FROM payroll_runs WHERE id = ?', [$runId])->fetch();
        if (!$run) {
            throw new \RuntimeException('Liquidación no encontrada');
        }
        if (($run['status'] ?? '') === 'cancelled') {
            throw new \RuntimeException('La liquidación está cancelada');
        }

        $items = $this->query(
            "SELECT pi.*, e.document_type, e.document_number, e.first_name, e.last_name, e.salary,
                    e.eps_code, e.afp_code, e.arl_code, e.caja_code, e.cotizante_type, e.cotizante_subtype,
                    e.arl_rate, e.exempt_1141, e.has_transport_aid
             FROM payroll_items pi
             JOIN employees e ON e.id = pi.employee_id
             WHERE pi.payroll_run_id = ?
             ORDER BY pi.id",
            [$runId]
        )->fetchAll();

        $p = (new PayrollService($this->db))->params();
        $smmlv = (float)($p['smmlv'] ?? 0);
        $ibcCap = round($smmlv * max(1.0, (float)($p['ibc_cap_smmlv'] ?? 25)), 2);
        $periodDays = max(1, min(30, (int)($run['days_worked'] ?? 30)));
        $period = sprintf('%04d-%02d', (int)$run['period_year'], (int)$run['period_month']);

        $rows = [];
        $issues = [];
        $totals = [
            'employees' => 0,
            'ibc' => 0.0,
            'health_emp' => 0.0,
            'pension_emp' => 0.0,
            'fsp' => 0.0,
            'health_er' => 0.0,
            'pension_er' => 0.0,
            'arl' => 0.0,
            'caja' => 0.0,
            'sena' => 0.0,
            'icbf' => 0.0,
            'pila_total' => 0.0,
        ];

        foreach ($items as $it) {
            $name = trim((string)($it['employee_name'] ?? (($it['first_name'] ?? '') . ' ' . ($it['last_name'] ?? ''))));
            $worked = (int)($it['days_worked'] ?? 0);
            $vacDays = (int)($it['vacation_days'] ?? 0);
            $incapDays = (int)($it['incapacity_days'] ?? 0);
            $unpaid = (int)($it['unpaid_days'] ?? 0);
            // Días cotizados aproximados: laborados + vacaciones + incapacidad (sin no remunerados).
            $daysCot = max(0, min(30, $worked + $vacDays + $incapDays));
            if ($daysCot <= 0 && $periodDays > 0 && $unpaid < $periodDays) {
                $daysCot = max(1, $periodDays - $unpaid);
            }

            $ibc = round((float)($it['ibc'] ?? 0), 2);
            if ($ibc <= 0) {
                // Recalcular fallback para liquidaciones previas a Fase 2.
                $ibc = round(
                    (float)($it['salary_base'] ?? 0)
                    + (float)($it['vacation_pay'] ?? 0)
                    + (float)($it['other_earnings'] ?? 0),
                    2
                );
                if ($ibc <= 0 && $incapDays > 0) {
                    $ibc = round(((float)($it['salary'] ?? 0) / 30) * max(1, min($incapDays, $periodDays)), 2);
                }
            }
            if ($ibcCap > 0 && $ibc > $ibcCap) {
                $ibc = $ibcCap;
            }

            $eps = strtoupper(trim((string)($it['eps_code'] ?? '')));
            $afp = strtoupper(trim((string)($it['afp_code'] ?? '')));
            $arl = strtoupper(trim((string)($it['arl_code'] ?? '')));
            $caja = strtoupper(trim((string)($it['caja_code'] ?? '')));
            $cotType = (string)($it['cotizante_type'] ?? '1') ?: '1';
            $cotSub = str_pad((string)($it['cotizante_subtype'] ?? '00'), 2, '0', STR_PAD_LEFT);

            $rowIssues = [];
            if ($eps === '') {
                $rowIssues[] = 'Falta código EPS';
            }
            if ($afp === '') {
                $rowIssues[] = 'Falta código AFP';
            }
            if ($arl === '') {
                $rowIssues[] = 'Falta código ARL';
            }
            if ((float)($it['caja_employer'] ?? 0) > 0 && $caja === '') {
                $rowIssues[] = 'Falta código Caja (hay aporte CCF)';
            }
            if ($daysCot < 1 || $daysCot > 30) {
                $rowIssues[] = 'Días cotizados fuera de 1–30 (' . $daysCot . ')';
            }
            if ($ibc <= 0) {
                $rowIssues[] = 'IBC en cero';
            }
            if ($smmlv > 0 && $daysCot > 0) {
                $minIbc = round($smmlv * $daysCot / 30, 2);
                if ($ibc + 0.01 < $minIbc && (float)($it['salary'] ?? 0) >= $smmlv - 0.01) {
                    $rowIssues[] = 'IBC bajo el mínimo proporcional ($' . number_format($minIbc, 0, ',', '.') . ')';
                }
            }
            if (trim((string)($it['document_number'] ?? '')) === '') {
                $rowIssues[] = 'Sin documento';
            }

            foreach ($rowIssues as $msg) {
                $issues[] = [
                    'level' => str_contains($msg, 'Falta') || str_contains($msg, 'cero') || str_contains($msg, 'documento') ? 'error' : 'warn',
                    'employee' => $name,
                    'message' => $msg,
                ];
            }

            $healthEmp = round((float)($it['health_employee'] ?? 0), 2);
            $pensionEmp = round((float)($it['pension_employee'] ?? 0), 2);
            $fsp = round((float)($it['fsp_employee'] ?? 0), 2);
            $healthEr = round((float)($it['health_employer'] ?? 0), 2);
            $pensionEr = round((float)($it['pension_employer'] ?? 0), 2);
            $arlAmt = round((float)($it['arl_employer'] ?? 0), 2);
            $cajaAmt = round((float)($it['caja_employer'] ?? 0), 2);
            $senaAmt = round((float)($it['sena_employer'] ?? 0), 2);
            $icbfAmt = round((float)($it['icbf_employer'] ?? 0), 2);
            $lineTotal = round(
                $healthEmp + $pensionEmp + $fsp + $healthEr + $pensionEr + $arlAmt + $cajaAmt + $senaAmt + $icbfAmt,
                2
            );

            $exempt = !empty($it['exempt_1141']) || ($healthEr <= 0.009 && $senaAmt <= 0.009 && $icbfAmt <= 0.009
                && (float)($it['salary'] ?? 0) > 0 && (float)($it['salary'] ?? 0) < $smmlv * 10);

            $rows[] = [
                'employee_id' => (int)$it['employee_id'],
                'employee_name' => $name,
                'document_type' => (string)($it['document_type'] ?? 'CC'),
                'document_number' => preg_replace('/\s+/', '', (string)($it['document_number'] ?? '')),
                'cotizante_type' => $cotType,
                'cotizante_subtype' => $cotSub,
                'eps_code' => $eps,
                'afp_code' => $afp,
                'arl_code' => $arl,
                'caja_code' => $caja,
                'days_cotizados' => $daysCot,
                'days_worked' => $worked,
                'days_vacation' => $vacDays,
                'days_incapacity' => $incapDays,
                'days_unpaid' => $unpaid,
                'salary_base_contract' => round((float)($it['salary'] ?? 0), 2),
                'ibc' => $ibc,
                'health_employee' => $healthEmp,
                'pension_employee' => $pensionEmp,
                'fsp_employee' => $fsp,
                'health_employer' => $healthEr,
                'pension_employer' => $pensionEr,
                'arl_employer' => $arlAmt,
                'caja_employer' => $cajaAmt,
                'sena_employer' => $senaAmt,
                'icbf_employer' => $icbfAmt,
                'pila_line_total' => $lineTotal,
                'exempt_1141' => $exempt ? 'S' : 'N',
                'issues' => $rowIssues,
            ];

            $totals['employees']++;
            $totals['ibc'] += $ibc;
            $totals['health_emp'] += $healthEmp;
            $totals['pension_emp'] += $pensionEmp;
            $totals['fsp'] += $fsp;
            $totals['health_er'] += $healthEr;
            $totals['pension_er'] += $pensionEr;
            $totals['arl'] += $arlAmt;
            $totals['caja'] += $cajaAmt;
            $totals['sena'] += $senaAmt;
            $totals['icbf'] += $icbfAmt;
            $totals['pila_total'] += $lineTotal;
        }

        foreach ($totals as $k => $v) {
            if ($k !== 'employees') {
                $totals[$k] = round((float)$v, 2);
            }
        }

        $errorCount = count(array_filter($issues, static fn ($i) => ($i['level'] ?? '') === 'error'));

        return [
            'run' => $run,
            'company' => [
                'name' => $this->setting('company_name', 'Empresa'),
                'nit' => preg_replace('/\s+/', '', $this->setting('company_nit', '')),
            ],
            'period' => $period,
            'rows' => $rows,
            'totals' => $totals,
            'issues' => $issues,
            'ready' => $errorCount === 0 && $rows !== [],
        ];
    }

    /** @return list<list<string|int|float>> */
    public function csvRows(array $bundle): array
    {
        $out = [];
        foreach ($bundle['rows'] as $r) {
            $out[] = [
                $bundle['period'],
                'E',
                $bundle['company']['nit'],
                $bundle['company']['name'],
                $r['document_type'],
                $r['document_number'],
                $r['employee_name'],
                $r['cotizante_type'],
                $r['cotizante_subtype'],
                $r['eps_code'],
                $r['afp_code'],
                $r['arl_code'],
                $r['caja_code'],
                $r['days_cotizados'],
                number_format((float)$r['salary_base_contract'], 2, '.', ''),
                number_format((float)$r['ibc'], 2, '.', ''),
                number_format((float)$r['health_employee'], 2, '.', ''),
                number_format((float)$r['pension_employee'], 2, '.', ''),
                number_format((float)$r['fsp_employee'], 2, '.', ''),
                number_format((float)$r['health_employer'], 2, '.', ''),
                number_format((float)$r['pension_employer'], 2, '.', ''),
                number_format((float)$r['arl_employer'], 2, '.', ''),
                number_format((float)$r['caja_employer'], 2, '.', ''),
                number_format((float)$r['sena_employer'], 2, '.', ''),
                number_format((float)$r['icbf_employer'], 2, '.', ''),
                number_format((float)$r['pila_line_total'], 2, '.', ''),
                $r['exempt_1141'],
                $r['days_worked'],
                $r['days_vacation'],
                $r['days_incapacity'],
                $r['days_unpaid'],
                $r['issues'] ? implode('; ', $r['issues']) : '',
            ];
        }
        return $out;
    }

    public function csvHeaders(): array
    {
        return [
            'Periodo', 'Tipo planilla', 'NIT aportante', 'Razon social',
            'Tipo doc', 'Documento', 'Empleado', 'Tipo cotizante', 'Subtipo',
            'EPS', 'AFP', 'ARL', 'Caja', 'Dias cotizados',
            'Salario contrato', 'IBC',
            'Salud emp', 'Pension emp', 'FSP',
            'Salud empresa', 'Pension empresa', 'ARL aporte', 'Caja aporte', 'SENA', 'ICBF',
            'Total linea PILA', 'Exoneracion 114-1',
            'Dias laborados', 'Dias vacaciones', 'Dias incapacidad', 'Dias no remunerados',
            'Observaciones validacion',
        ];
    }

    /** Texto plano separado por pipe (útil para operadores / revisión). */
    public function plainText(array $bundle): string
    {
        $lines = [];
        $lines[] = '# PILA operativa Seri ERP · Periodo ' . $bundle['period'] . ' · Planilla E';
        $lines[] = '# Aportante: ' . $bundle['company']['nit'] . ' · ' . $bundle['company']['name'];
        $lines[] = '# Total estimado PILA: ' . number_format((float)$bundle['totals']['pila_total'], 2, '.', '');
        $lines[] = 'SEQ|TIPO_DOC|DOC|NOMBRE|TIPO_COT|SUBTIPO|EPS|AFP|ARL|CAJA|DIAS|IBC|SALUD_E|PENS_E|FSP|SALUD_P|PENS_P|ARL_P|CAJA_P|SENA|ICBF|TOTAL|EXO1141';
        $i = 1;
        foreach ($bundle['rows'] as $r) {
            $lines[] = implode('|', [
                $i++,
                $r['document_type'],
                $r['document_number'],
                str_replace('|', '/', $r['employee_name']),
                $r['cotizante_type'],
                $r['cotizante_subtype'],
                $r['eps_code'],
                $r['afp_code'],
                $r['arl_code'],
                $r['caja_code'],
                $r['days_cotizados'],
                number_format((float)$r['ibc'], 2, '.', ''),
                number_format((float)$r['health_employee'], 2, '.', ''),
                number_format((float)$r['pension_employee'], 2, '.', ''),
                number_format((float)$r['fsp_employee'], 2, '.', ''),
                number_format((float)$r['health_employer'], 2, '.', ''),
                number_format((float)$r['pension_employer'], 2, '.', ''),
                number_format((float)$r['arl_employer'], 2, '.', ''),
                number_format((float)$r['caja_employer'], 2, '.', ''),
                number_format((float)$r['sena_employer'], 2, '.', ''),
                number_format((float)$r['icbf_employer'], 2, '.', ''),
                number_format((float)$r['pila_line_total'], 2, '.', ''),
                $r['exempt_1141'],
            ]);
        }
        return implode("\n", $lines) . "\n";
    }
}
