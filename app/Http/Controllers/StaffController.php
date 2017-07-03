<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;
use App\Staff;
use App\Role;
use App\User;
use Yajra\Datatables\Html\Builder;
use Yajra\Datatables\Facades\Datatables;
use App\Http\Requests\StoreMemberRequest;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Mail;
use App\Http\Requests\UpdateMemberRequest;
use Excel;
use PDF;
use Illuminate\Support\Facades\Auth;

class StaffController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request, Builder $htmlBuilder)
    {
        if ($request->ajax()) {
            $staff = Role::where('name', 'staff')->first()->users;
            return Datatables::of($staff)
                ->addColumn('name', function($staff) {
                    return '<a href="'.route('staff.show', $staff->id).'">'.$staff->name.'</a>';
                })
                ->addColumn('nip', function($staff) {
                    return $staff->staff->nip;
                })
                ->addColumn('action', function($staff){
                    return view('datatable._action', [
                        'model'           => $staff,
                        'form_url'        => route('staff.destroy', $staff->id),
                        'edit_url' => route('staff.edit', $staff->id),
                        'confirm_message' => 'Yakin mau menghapus ' . $staff->name . '?'
                    ]);
                })->make(true);
        }

        $html = $htmlBuilder
            ->addColumn(['data' => 'nip', 'name'=>'nip', 'title'=>'NIP'])
            ->addColumn(['data' => 'name', 'name'=>'name', 'title'=>'Nama'])
            ->addColumn(['data' => 'email', 'name'=>'email', 'title'=>'Email'])
            ->addColumn(['data' => 'action', 'name'=>'action', 'title'=>'', 'orderable'=>false, 'searchable'=>false]);

        return view('staff.index', compact('html'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('staff.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $this->validate($request, ['nip' => 'required|unique:staff', 'email' => 'required|unique:users']);
        $password = str_random(6);
        $data = $request->only('name','email');
        $data['password'] = bcrypt($password);
        // bypass verifikasi
        $data['is_verified'] = 1;
        $data['role'] = 'staff';
        $staff = User::create($data);

        $data_user = $request->only('nip','telp','jenis_kelamin','alamat');
        $data_user['user_id'] = $staff->id;
        $staff_data = Staff::create($data_user);
        // set role
        $staffRole = Role::where('name', 'staff')->first();
        $staff->attachRole($staffRole);

        // kirim email
        Mail::send('auth.emails.invite', compact('staff', 'password'), function ($m) use ($staff) {
            $m->to($staff->email, $staff->name)->subject('Anda telah didaftarkan di Dinas Kearsipan dan Perpustakaan Provinsi Bali!');
        });

        Session::flash("flash_notification", [
            "level"   => "success",
            "message" => "Berhasil menyimpan member dengan email " .
            "<strong>" . $data['email'] . "</strong>" .
            " dan password <strong>" . $password . "</strong>."
        ]);

        return redirect()->route('staff.index');
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $user = User::find($id);
        return view('staff.show', compact('user'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $staff = User::find($id);
        return view('staff.edit')->with(compact('staff'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $this->validate($request, ['email' => 'required|unique:staff,email,'. $id]);
        $staff = User::find($id);
        $staff->update($request->only('name','email'));
        
        $staff_data = Staff::where('user_id',$id)->first();
        $staff_data->update($request->only('nip','telp','jenis_kelamin','alamat'));

        Session::flash("flash_notification", [
            "level"=>"success",
            "message"=>"Berhasil menyimpan $staff->name"
        ]);

        return redirect()->route('staff.index');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $staff = User::find($id);
        
        if($staff){
            if ($staff->hasRole('staff')) {
                $staff->delete();
                // $staff_data = Staff::where('user_id',$id)->first();
                // $staff_data->delete();
                Session::flash("flash_notification", [
                    "level"=>"success",
                    "message"=>"Staff berhasil dihapus"
                ]);
            }
        }

        return redirect()->route('staff.index');
    }

    public function export() 
    { 
        return view('staff.export');
    }

    public function exportPost(Request $request) 
    { 
        // validasi
        $this->validate($request, [
            'type'=>'required|in:pdf,xls'
        ]);

        $staff = Role::where('name', 'staff')->first()->users;
        
        $handler = 'export' . ucfirst($request->get('type'));
        return $this->$handler($staff);
    }

    private function exportXls($staffs)
    {
        Excel::create('Data Staff Perpustakaan', function($excel) use ($staffs) {
            // Set the properties
            $excel->setTitle('Data Staff Perpustakaan')
                ->setCreator(Auth::user()->name);

            $excel->sheet('Data Staff', function($sheet) use ($staffs) {
                $row = 1;
                $sheet->row($row, [
                    'NIP',
                    'Nama',
                    'Email',
                    'Jenis Kelamin',
                    'Nomor Telp',
                    'Alamat'
                ]);
                foreach ($staffs as $staff) {
                    $sheet->row(++$row, [
                        $staff->staff->nip,
                        $staff->name,
                        $staff->email,
                        $staff->staff->jenis_kelamin,
                        $staff->staff->telp,
                        $staff->staff->alamat
                    ]);
                }
            });
        })->export('xls');
    }

    private function exportPdf($staffs)
    {
        $pdf = PDF::loadview('pdf.staff', compact('staffs'));
        return $pdf->download('staff.pdf');
    }
}