<?php

namespace App\Http\Controllers;

use App\Expense;
use App\Jobs\PostExpense;
use App\Jobs\UploadExpense;
use App\Mail\DeclineExpense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
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
            'amount' => 'required|numeric|gt:0',
            'file' => 'required|file|mimes:jpeg,bmp,png,gif,pdf'
        ]);

        $user = Auth::user();

        $expense = Expense::create([
            'user_id' => $user->id,
            'department' => $data['department'],
            'activity' => $data['activity'],
            'amount' => $data['amount'],
            'creditor' => $user->name
        ]);

        $expense->fresh();

        $path = Storage::putFileAs('public/expenses', $request->file('file'), $expense->id . " - " . $expense->department . " " . str_replace('/', '_', $expense->activity) . " - " . $expense->creditor . "." . $request->file('file')->extension());
        $path = str_replace('public/', '', $path);

        $expense->file_path = $path;

        $expense->save();

        return redirect('/expense/create')->with('success', 'Dit bilag er blevet uploadet!');

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
        $expense->approved_at = now();

        $expense->save();

        UploadExpense::dispatch($expense);
        PostExpense::dispatch($expense);

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

        Mail::to($expense->user->email)->send(new DeclineExpense($expense));

        return redirect()->to('/expense/approve');
    }

    public static function transfer() {

        // Get all non-uploaded files
        $expenses = Expense::where([
            ['uploaded', '=', 0],
            ['approved', '=', 1]
        ])->get();

        foreach ($expenses as $expense) {

            UploadExpense::dispatch($expense);

        }

    }

    private function findNextId() {

        $approvedExpenses = Expense::where([
            ['ph_id', '<>', null]
        ])->get()->sortByDesc('ph_id');

        try {
            $lastId = $approvedExpenses->first()->ph_id;
            $lastId = (int) ltrim($lastId, '0');

            if ($lastId < 1000) {
                $nextId = str_repeat('0', 3 - strlen(++$lastId)) . $lastId;
            } else {
                $nextId = (string) ($lastId + 1);
            }

            return $nextId;
        } catch (\ErrorException $exception) {
            return "001";
        }

    }

}
