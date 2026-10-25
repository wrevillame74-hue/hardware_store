<?php

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Generate a PDF receipt for an order.
 * Returns the temporary PDF file path.
 * The caller is responsible for deleting the file after emailing it.
 */
function generate_order_receipt_pdf($conn, $order_id)
{
    require_once __DIR__ . '/../vendor/autoload.php';

    $order_result = mysqli_execute_query(
        $conn,
        "SELECT * FROM orderinfo WHERE order_id = ?",
        [$order_id]
    );

    $order = mysqli_fetch_assoc($order_result);

    if (!$order) {
        throw new RuntimeException('Order not found for PDF receipt.');
    }

    $lines_result = mysqli_execute_query(
        $conn,
        "SELECT * FROM orderdetails WHERE order_id = ?",
        [$order_id]
    );

    $lines = [];
    $customer = null;
    $subtotal = 0;

    while ($line = mysqli_fetch_assoc($lines_result)) {
        $customer = $line;
        $subtotal += (float)$line['line_total'];
        $lines[] = $line;
    }

    if (!$customer || !$lines) {
        throw new RuntimeException('No order details found for PDF receipt.');
    }

    $escape = static function ($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    };

    $shipping = (float)$order['shipping_fee'];
    $total = $subtotal + $shipping;

    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body {
                font-family: DejaVu Sans, sans-serif;
                font-size: 12px;
                color: #222;
            }
            h1 {
                text-align: center;
                margin-bottom: 5px;
            }
            .subtitle {
                text-align: center;
                margin-bottom: 25px;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 20px;
            }
            th, td {
                border: 1px solid #ccc;
                padding: 8px;
                text-align: left;
            }
            th {
                background: #eee;
            }
            .number {
                text-align: right;
            }
            .totals {
                width: 45%;
                margin-left: auto;
            }
            .totals td {
                border: none;
                padding: 5px;
            }
            .grand-total {
                font-weight: bold;
                border-top: 1px solid #333;
            }
        </style>
    </head>
    <body>
        <h1>Sturdy Supplies</h1>
        <div class="subtitle">Order Receipt</div>

        <p>
            <strong>Order #:</strong> ' . (int)$order_id . '<br>
            <strong>Date:</strong> ' . $escape($order['order_date']) . '<br>
            <strong>Status:</strong> ' . $escape(ucfirst($order['status'])) . '
        </p>

        <p>
            <strong>Customer:</strong> ' .
            $escape($customer['fname'] . ' ' . $customer['lname']) . '<br>
            <strong>Address:</strong> ' . $escape($customer['address']) . '<br>
            <strong>Contact:</strong> ' . $escape($customer['contact']) . '
        </p>

        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Qty</th>
                    <th>Unit Price</th>
                    <th class="number">Line Total</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($lines as $line) {
        $html .= '
                <tr>
                    <td>' . $escape($line['item_name']) . '</td>
                    <td>' . (int)$line['quantity'] . '</td>
                    <td>PHP ' . number_format((float)$line['price_each'], 2) . '</td>
                    <td class="number">PHP ' .
                        number_format((float)$line['line_total'], 2) .
                    '</td>
                </tr>';
    }

    $html .= '
            </tbody>
        </table>

        <table class="totals">
            <tr>
                <td>Subtotal</td>
                <td class="number">PHP ' . number_format($subtotal, 2) . '</td>
            </tr>
            <tr>
                <td>Shipping</td>
                <td class="number">PHP ' . number_format($shipping, 2) . '</td>
            </tr>
            <tr class="grand-total">
                <td>Grand Total</td>
                <td class="number">PHP ' . number_format($total, 2) . '</td>
            </tr>
        </table>

        <p>Thank you for shopping with Sturdy Supplies!</p>
    </body>
    </html>';

    $options = new Options();
    $options->set('isRemoteEnabled', false);

    $pdf = new Dompdf($options);
    $pdf->loadHtml($html, 'UTF-8');
    $pdf->setPaper('A4', 'portrait');
    $pdf->render();

    $pdf_path = tempnam(sys_get_temp_dir(), 'sturdy_receipt_');

    if ($pdf_path === false) {
        throw new RuntimeException('Could not create temporary PDF file.');
    }

    if (file_put_contents($pdf_path, $pdf->output()) === false) {
        @unlink($pdf_path);
        throw new RuntimeException('Could not save the PDF receipt.');
    }

    return $pdf_path;
}