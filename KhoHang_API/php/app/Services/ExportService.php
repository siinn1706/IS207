<?php

namespace App\Services;

use App\Support\Legacy;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\IOFactory;

/** Port of export_service.py: Excel from the same templates, PDF with the same layout. */
class ExportService
{
    private static function formatDate(string $s): string
    {
        $dt = Legacy::fromIso($s);

        return $dt ? $dt->format('d/m/Y') : $s;
    }

    private static function num(float $n): string
    {
        return number_format(round($n), 0, '', '.');
    }

    public static function filename(array $d, string $ext): string
    {
        $dt = Legacy::fromIso($d['voucher_date']) ?? new \DateTimeImmutable('now');
        $no = str_replace(['/', '\\'], '-', $d['voucher_no']);
        $wh = str_replace(['/', '\\'], '-', $d['warehouse_code']);

        return "{$d['voucher_type']}_{$wh}_".$dt->format('Ym')."_$no.$ext";
    }

    /** @return array{0:string,1:string} [bytes, filename] */
    public static function excel(array $d): array
    {
        $dir = config('khohang.templates_dir');
        $path = $d['voucher_type'] === 'PN' ? "$dir/Mau_Phieu_Nhap_TEMPLATE.xlsx" : "$dir/Mau_Phieu_Xuat_TEMPLATE.xlsx";
        if (! is_file($path)) {
            throw new \RuntimeException("Template not found: $path");
        }
        $book = IOFactory::load($path);
        $ws = $book->getActiveSheet();

        $ws->setCellValue('F7', $d['voucher_no']);
        $ws->setCellValue('D2', self::formatDate($d['voucher_date']));
        $ws->setCellValue('C9', $d['partner_name']);
        if ($d['invoice_no']) {
            $ws->setCellValue('E10', $d['invoice_no']);
        }
        $ws->setCellValue('C11', $d['warehouse_code']);
        if ($d['warehouse_location']) {
            $ws->setCellValue('F11', $d['warehouse_location']);
        }

        $total = 0.0;
        for ($i = 0; $i < 18; $i++) {
            $row = 16 + $i;
            if ($i < count($d['items'])) {
                $it = $d['items'][$i];
                $total += $it['qty_actual'] * $it['unit_price'];
                $ws->setCellValue("A$row", $i + 1);
                $ws->setCellValue("B$row", $it['name']);
                $ws->setCellValue("D$row", $it['sku']);
                $ws->setCellValue("E$row", $it['unit']);
                $ws->setCellValue("F$row", $it['qty_doc']);
                $ws->setCellValue("G$row", $it['qty_actual']);
                $ws->setCellValue("H$row", $it['unit_price']);
            } else {
                foreach (['A', 'B', 'D', 'E', 'F', 'G', 'H'] as $c) {
                    $ws->setCellValue("$c$row", null);
                }
            }
        }
        $tax = (float) ($d['tax_rate'] ?: 0);
        $taxAmount = $total * $tax / 100;
        $grand = $total + $taxAmount;
        $ws->setCellValue('B34', $tax > 0 ? 'Thuế '.sprintf('%.0f', $tax).'%' : 'Thuế 0%');
        $ws->setCellValue('I34', $tax > 0 ? $taxAmount : null);
        $ws->setCellValue('C36', NumberToWordsVi::amount($grand));
        if ($d['attachments']) {
            $ws->setCellValue('C37', $d['attachments']);
        }
        foreach (['prepared_by' => 'B43', 'receiver' => 'D43', 'storekeeper' => 'F43', 'director' => 'H43'] as $k => $cell) {
            if ($d[$k]) {
                $ws->setCellValue($cell, $d[$k]);
            }
        }

        $writer = IOFactory::createWriter($book, 'Xlsx');
        ob_start();
        $writer->save('php://output');

        return [(string) ob_get_clean(), self::filename($d, 'xlsx')];
    }

    /** @return array{0:string,1:string} [bytes, filename] */
    public static function pdf(array $d): array
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $title = $d['voucher_type'] === 'PN' ? 'PHIEU NHAP KHO' : 'PHIEU XUAT KHO';
        $rows = '';
        $total = 0.0;
        foreach ($d['items'] as $i => $it) {
            $line = $it['qty_actual'] * $it['unit_price'];
            $total += $line;
            $name = mb_strlen($it['name']) > 30 ? mb_substr($it['name'], 0, 30).'...' : $it['name'];
            $rows .= '<tr><td class="c">'.($i + 1).'</td><td>'.$e($name).'</td><td>'.$e($it['sku']).'</td><td>'.$e($it['unit']).'</td>'
                .'<td class="r">'.self::num($it['qty_doc']).'</td><td class="r">'.self::num($it['qty_actual']).'</td>'
                .'<td class="r">'.self::num($it['unit_price']).'</td><td class="r">'.self::num($line).'</td></tr>';
        }
        $tax = (float) ($d['tax_rate'] ?: 0);
        $taxAmount = $total * $tax / 100;
        $grand = $total + $taxAmount;
        $summary = '<tr><td class="r">Cong:</td><td class="r">'.self::num($total).'</td></tr>';
        if ($tax > 0) {
            $summary .= '<tr><td class="r">Thue ('.sprintf('%.0f', $tax).'%):</td><td class="r">'.self::num($taxAmount).'</td></tr>'
                .'<tr><td class="r b">TONG CONG:</td><td class="r b">'.self::num($grand).'</td></tr>';
        } else {
            $summary .= '<tr><td class="r b">TONG CONG:</td><td class="r b">'.self::num($total).'</td></tr>';
        }
        $invoice = '';
        if ($d['invoice_no']) {
            $invoice = '<tr><td class="r">So hoa don:</td><td>'.$e($d['invoice_no']).'</td><td class="r">Ngay HD:</td><td>'
                .$e($d['invoice_date'] ? self::formatDate($d['invoice_date']) : '').'</td></tr>';
        }
        $words = $e(NumberToWordsVi::amount($grand));
        $sig = fn ($k) => $e($d[$k] ?? '');
        $html = <<<HTML
<html><head><meta charset="utf-8"><style>
body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; }
.h { text-align: center; font-size: 12px; margin: 0 0 3px; }
.t { text-align: center; font-size: 14px; font-weight: bold; margin: 10px 0; }
table { border-collapse: collapse; }
.items { width: 100%; } .items th, .items td { border: 0.5px solid #000; padding: 4px; font-size: 8px; }
.items th { background: #808080; color: #f5f5f5; font-size: 9px; }
.c { text-align: center; } .r { text-align: right; } .b { font-weight: bold; }
.info td { font-size: 9px; padding: 0 4px 5px; } .sum { margin-left: auto; } .sum td { font-size: 9px; padding: 2px 4px; }
.sig { width: 100%; margin-top: 20px; } .sig td { text-align: center; width: 25%; padding-bottom: 10px; }
</style></head><body>
<p class="h">CONG TY TNHH QUAN LY KHO N3T</p>
<p>Dia chi: Duong Dai hoc, P. Linh Trung, TP. Thu Duc</p>
<p class="t">{$title}</p>
<table class="info">
<tr><td class="r">So phieu:</td><td>{$e($d['voucher_no'])}</td><td class="r">Ngay:</td><td>{$e(self::formatDate($d['voucher_date']))}</td></tr>
<tr><td class="r">Doi tac:</td><td>{$e($d['partner_name'])}</td><td class="r">Kho:</td><td>{$e($d['warehouse_code'])}</td></tr>
{$invoice}
</table><br>
<table class="items"><tr><th>STT</th><th>Ten hang hoa</th><th>Ma hang</th><th>DVT</th><th>SL CT</th><th>SL TT</th><th>Don gia</th><th>Thanh tien</th></tr>
{$rows}</table><br>
<table class="sum">{$summary}</table>
<p><i>Bang chu: {$words}</i></p>
<table class="sig">
<tr><td class="b">Nguoi lap phieu</td><td class="b">Nguoi giao/nhan</td><td class="b">Thu kho</td><td class="b">Giam doc</td></tr>
<tr><td><i>(Ky, ghi ro ho ten)</i></td><td><i>(Ky, ghi ro ho ten)</i></td><td><i>(Ky, ghi ro ho ten)</i></td><td><i>(Ky, ghi ro ho ten)</i></td></tr>
<tr><td style="height:40px"></td><td></td><td></td><td></td></tr>
<tr><td>{$sig('prepared_by')}</td><td>{$sig('receiver')}</td><td>{$sig('storekeeper')}</td><td>{$sig('director')}</td></tr>
</table></body></html>
HTML;
        $opts = new Options;
        $opts->set('isRemoteEnabled', false);
        $opts->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($opts);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return [$pdf->output(), self::filename($d, 'pdf')];
    }
}
