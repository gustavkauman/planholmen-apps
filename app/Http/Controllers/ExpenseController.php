<?php

namespace App\Http\Controllers;

use App\Expense;
use Google_Service_Drive_DriveFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class ExpenseController extends Controller
{
    public function index()
    {
        $expenses = Expense::all();

        return view('expense.index', compact('expenses'));
    }

    public function create()
    {
        return view('expense.create');
    }

    public function store(Request $request)
    {

        $data = $request->validate([
            'department' => 'required|min:2',
            'activity' => 'required|min:2',
            'amount' => 'required|min:0',
            'file' => 'required|file|mimes:jpeg,bmp,png,gif,pdf'
        ]);

        $user = Auth::user();

        $expense = Expense::create([
            'department' => $data['department'],
            'activity' => $data['activity'],
            'amount' => $data['amount'],
            'creditor' => $user->name
        ]);

        $expense->fresh();

        $path = Storage::putFileAs('public/expenses', $request->file('file'), $expense->id . " - " . $expense->department . " " . $expense->activity . " - " . $expense->creditor . "." . $request->file('file')->extension());
        $path = str_replace('public/', '', $path);

        $expense->file_path = $path;

        $expense->save();

        return redirect('/expense/create');

    }

    public function approve($id = 0)
    {
        $expenses = Expense::where([
            ['uploaded', '=', 0],
            ['approved', '=', 0]
        ])->get()->toArray();

        return view('expense.approve', compact('expenses', 'id'));
    }

    public function accept($id, $next = false)
    {
        $expense = Expense::find($id);
        $expense->approved = 1;

        $expense->ph_id = $this->findNextId();

        $expense->save();

        if ( $next != false ) {
            return redirect()->to('/expense/approve/' . $next);
        }

        return redirect()->to('/expense/approve');

    }

    public function decline($id, $next = false)
    {
        $expense = Expense::find($id);
        $expense->approved = -1;
        $expense->save();

        if ( $next != false ) {
            return redirect()->to('/expense/approve/' . $next);
        }

        // TODO Send email to teamster saying their expense godt declined

        return redirect()->to('/expense/approve');
    }

    public static function transfer() {

        // Get all non-uploaded files
        $expenses = Expense::where([
            ['uploaded', '=', 0],
            ['approved', '=', 1]
        ])->get();

        foreach ($expenses as $expense) {

            $file = 'public/' . $expense->file_path;
            $name = $expense->ph_id . " - " . $expense->department . " " . $expense->activity . " - " . $expense->creditor . "." . File::extension($file);

            $metadata = new Google_Service_Drive_DriveFile(array(
                'name' => $name,
                'parents' => explode(",", env('DRIVE_EXPENSE_PARENT'))
            ));

            $content = Storage::get($file);

            GoogleDriveController::createFile($metadata, array(
                'data' => $content,
                'mimeType' => File::mimeType(Storage::path($file)),
                'uploadType' => 'multipart'
            ));

            $expense->uploaded = true;
            $expense->save();

        }

    }

    private function findNextId() {

        $approvedExpenses = Expense::where([
            ['ph_id', '<>', null]
        ])->get()->sortByDesc('ph_id');

        $lastId = $approvedExpenses->first()->ph_id;
        $lastId = (int) ltrim($lastId, '0');

        if ($lastId < 1000) {
            $nextId = str_repeat('0', 3 - strlen($lastId)) . ($lastId + 1);
        } else {
            $nextId = (string) ($lastId + 1);
        }

        return $nextId;

    }

}
