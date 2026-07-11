<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ToolFlowTest extends TestCase
{
    public function test_full_flow_upload_generate_download(): void
    {
        // Kopie verwenden: UploadedFile verschiebt die Datei beim Upload
        $fixture = sys_get_temp_dir().'/upload_'.uniqid().'.xlsx';
        copy(base_path('tests/golden/fixtures/orders_ahs_korneuburg.xlsx'), $fixture);

        $this->get('/')->assertOk()->assertSee('Weg 2: Datei hochladen');

        $upload = new UploadedFile($fixture, 'orders.xlsx', null, null, true);
        $response = $this->post('/upload', ['export' => $upload]);
        $response->assertRedirect();
        $jobUrl = $response->headers->get('Location');

        $this->get($jobUrl)->assertOk()->assertSee('Prüfbericht')->assertSee('34');

        $generate = $this->post($jobUrl.'/generate', [
            'ordername' => 'AHS Korneuburg',
            'orderinformation' => 'Testinfo',
        ]);
        $generate->assertRedirect();

        $result = $this->get($jobUrl.'/result');
        $result->assertOk()
            ->assertSee('AHS_Korneuburg')
            ->assertSee('Lieferanten-Report')
            ->assertSee('Verteil-PDF');

        $this->get($jobUrl.'/download/AHS_Korneuburg_orderreport_internal.xlsx')->assertOk();
        $this->get($jobUrl.'/download/AHS_Korneuburg_orderreport.pdf')->assertOk();
        $this->get($jobUrl.'/zip')->assertOk();

        // Nicht freigegebene Dateinamen werden abgelehnt
        $this->get($jobUrl.'/download/..%2F..%2F.env')->assertNotFound();
    }

    public function test_upload_without_required_columns_shows_errors(): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([['Spalte A', 'Spalte B'], ['x', 'y']]);
        $path = sys_get_temp_dir().'/invalid_'.uniqid().'.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);

        $upload = new UploadedFile($path, 'invalid.xlsx', null, null, true);
        $response = $this->post('/upload', ['export' => $upload]);
        $jobUrl = $response->headers->get('Location');

        $this->get($jobUrl)
            ->assertOk()
            ->assertSee('Pflichtspalte')
            ->assertDontSee('Dokumente erstellen');

        if (is_file($path)) {
            unlink($path);
        }
    }

    public function test_login_required_when_password_configured(): void
    {
        config(['ordersuite.password' => 'geheim']);

        $this->get('/')->assertRedirect('/login');
        $this->post('/login', ['password' => 'falsch'])->assertSessionHasErrors();
        $this->post('/login', ['password' => 'geheim'])->assertRedirect('/');
        $this->get('/')->assertOk();
    }
}
