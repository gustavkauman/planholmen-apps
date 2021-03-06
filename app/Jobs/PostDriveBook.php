<?php

namespace App\Jobs;

use App\CustomOption;
use App\Expense;
use App\Http\Controllers\DriveController;
use App\Http\Controllers\GoogleDriveController;
use App\Http\Controllers\GoogleSheetsController;
use Google_Service_Drive_DriveFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PostDriveBook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $drives;

    /**
     * Create a new job instance.
     *
     * @param Collection $drives
     */
    public function __construct(Collection $drives)
    {
        $this->drives = $drives;
    }

    /**
     * Execute the job.
     *
     * @return void
     * @throws \Exception
     */
    public function handle()
    {
        $user = $this->drives->first()->user;

        $title = str_replace(" ", "_", $user->name) . "_Koerebog_" . date('Y-m-d_H:i:s');

        $service = GoogleSheetsController::getService();

        $tempSheet = GoogleSheetsController::createSheet($title);
        $sheet = GoogleDriveController::copyFile($tempSheet->spreadsheetId, new Google_Service_Drive_DriveFile([
            'name' => $title,
            'parents' => explode(",", CustomOption::get("SHEETS_DRIVE_LEDGER_PARENT"))
        ]));

        GoogleDriveController::deleteFile($tempSheet->spreadsheetId);

        $values = [
            ['DDS Kørebog', '', '', '', ''],
            ['', '', '', '', '', ''],
            ['Navn: ', $user->name, '', '', '', ''],
            ['Adresse: ', '', '', '', '', ''],
            ['Registreringsnr.', '', '', '', '', ''],
            ['Bilagsnr. på afregningsark', '', '', '', '', ''],
            ['', '', '', '', '', ''],
            ['Dato', 'Kørslens mål', 'Kørslens formål', 'Antal KM', 'KM-sats', 'I alt']
        ];

        $sumKm = 0;$sumMoney = 0;
        $kmSats = CustomOption::get('KM_SATS');

        foreach ($this->drives as $drive) {
            $sumKm += $drive->distance;
            $sumMoney += ((int) $drive->distance * (double) $kmSats);

            $val = [
                $drive->date->format('d/m/Y'),
                $drive->from . " -> " . $drive->to,
                $drive->purpose,
                $drive->distance,
                $kmSats,
                ((int) $drive->distance * (double) $kmSats)
            ];

            array_push($values, $val);
        }

        $footer = [
            ['', '', '', '', '', ''],
            ['I alt', '', '', $sumKm, '', $sumMoney],
            ['', '', '', '', '', ''],
            ['', '', '', '', '', ''],
            ['Underskrift', $user->name, '', '', '', ''],
            ['', '', '', '', '', ''],
            ['Attesteret af:', '', '', '', '', ''],
            ['', '', '', '', '', ''],
            ['Underskrift', '', '', '', '', '']
        ];

        foreach ($footer as $line) {
            array_push($values, $line);
        }

        $service->spreadsheets_values->update($sheet->id, 'A1:Z1000', new \Google_Service_Sheets_ValueRange([
            'values' => $values
        ]), ['valueInputOption' => 'RAW']);

        DriveController::post($this->drives);

        $expense = Expense::create([
            'user_id' => $user->id,
            'department' => 'Team',
            'activity' => 'Team Transport',
            'amount' => $sumMoney,
            'creditor' => $user->name,
            'uploaded' => 1
        ]);

        foreach ($this->drives as $drive) {
            $drive->expense_id = $expense->id;
            $drive->save();
        }

    }
}
